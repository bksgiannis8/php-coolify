<?php

namespace App\Jobs;

use App\Actions\Docker\GetContainersStatus;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProcessStatus;
use App\Events\ApplicationStatusChanged;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
use App\Traits\ExecuteRemoteCommand;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use Visus\Cuid2\Cuid2;
use Yosymfony\Toml\Toml;

class ApplicationDeploymentJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, ExecuteRemoteCommand, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    public static int $batch_counter = 0;

    private int $application_deployment_queue_id;

    private bool $newVersionIsHealthy = false;

    private ApplicationDeploymentQueue $application_deployment_queue;

    private Application $application;

    private string $deployment_uuid;

    private int $pull_request_id;

    private string $commit;

    private bool $rollback;

    private bool $force_rebuild;

    private bool $restart_only;

    private ?string $dockerImage = null;

    private ?string $dockerImageTag = null;

    private GithubApp|GitlabApp|string $source = 'other';

    private StandaloneDocker|SwarmDocker $destination;

    // Deploy to Server
    private Server $server;

    // Build Server
    private Server $build_server;

    private bool $use_build_server = false;

    // Save original server between phases
    
    private function run_pre_deployment_command()
    {
        if (empty($this->application->pre_deployment_command)) {
            return;
        }
        $containers = getCurrentApplicationContainerStatus($this->server, $this->application->id, $this->pull_request_id);
        if ($containers->count() == 0) {
            return;
        }
        $this->application_deployment_queue->addLogEntry('Executing pre-deployment command (see debug log for output/errors).');

        foreach ($containers as $container) {
            $containerName = data_get($container, 'Names');
            if ($containers->count() == 1 || str_starts_with($containerName, $this->application->pre_deployment_command_container.'-'.$this->application->uuid)) {
                $cmd = "sh -c '".str_replace("'", "'\''", $this->application->pre_deployment_command)."'";
                $exec = "docker exec {$containerName} {$cmd}";
                $this->execute_remote_command(
                    [
                        'command' => $exec, 'hidden' => true,
                    ],
                );

                return;
            }
        }
        throw new RuntimeException('Pre-deployment command: Could not find a valid container. Is the container name correct?');
    }

    public function failed(Throwable $exception): void
    {
        $this->next(ApplicationDeploymentStatus::FAILED->value);
        $this->application_deployment_queue->addLogEntry('Oops something is not okay, are you okay? 😢', 'stderr');
        if (str($exception->getMessage())->isNotEmpty()) {
            $this->application_deployment_queue->addLogEntry($exception->getMessage(), 'stderr');
        }

        if ($this->application->build_pack !== 'dockercompose') {
            $code = $exception->getCode();
            ray($code);
            if ($code !== 69420) {
                // 69420 means failed to push the image to the registry, so we don't need to remove the new version as it is the currently running one
                $this->application_deployment_queue->addLogEntry('Deployment failed. Removing the new version of your application.', 'stderr');
                $this->execute_remote_command(
                    ["docker rm -f $this->container_name >/dev/null 2>&1", 'hidden' => true, 'ignore_errors' => true]
                );
            }
        }
    }
}
