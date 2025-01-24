<?php

namespace Brickhouse\Installer;

use Brickhouse\Console\Attributes\Argument;
use Brickhouse\Console\Attributes\Option;
use Brickhouse\Console\Command;
use Brickhouse\Console\InputOption;
use Brickhouse\Process\Process;

use function Brickhouse\Console\Prompts\confirm;
use function Brickhouse\Console\Prompts\text;

final class NewCommand extends Command
{
    /**
     * The name of the console command.
     *
     * @var string
     */
    public string $name = 'new';

    /**
     * The description of the console command.
     *
     * @var string
     */
    public string $description = 'Creates a new Brickhouse application.';

    /**
     * Defines the name of the project.
     *
     * @var string
     */
    #[Argument("name", null, InputOption::OPTIONAL)]
    public null|string $directory = null;

    /**
     * Whether to create the project, even if the directory already exists.
     *
     * @var bool
     */
    #[Option("force", 'f', 'Forces install even if the directory already exists', InputOption::NEGATABLE)]
    public bool $force = false;

    /**
     * Whether to supress command outputs.
     *
     * @var bool
     */
    #[Option("quiet", 'q', 'Supresses all command outputs.', InputOption::NEGATABLE)]
    public bool $quiet = false;

    /**
     * Whether to initialize a Git repository in the project.
     *
     * @var null|bool
     */
    #[Option("git", null, 'Whether to initialize a Git repository in the project.', InputOption::NEGATABLE)]
    public null|bool $initializeGit = null;

    /**
     * Whether to create the project as an API-only project or not.
     *
     * @var null|bool
     */
    #[Option("api", null, 'Creates an API-only project.', InputOption::NEGATABLE)]
    public null|bool $apiOnly = null;

    /**
     * Whether to use Pest instead of PHPUnit for test running.
     *
     * @var null|bool
     */
    #[Option("pest", null, 'Uses Pest instead of PHPUnit', InputOption::NEGATABLE)]
    public null|bool $usePest = null;

    /**
     * Defines the name or path to the PHP binary.
     *
     * @var string
     */
    #[Option("php-binary", null, 'Name or path to the PHP binary.', InputOption::REQUIRED)]
    public string $phpBinary = 'php';

    /**
     * Defines the name or path to the Composer binary.
     *
     * @var string
     */
    #[Option("composer-binary", null, 'Name or path to the Composer binary.', InputOption::REQUIRED)]
    public string $composerBinary = 'composer';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->writeHtml(<<<HTML
        <div class="m-1">
            <div class="px-1 bg-orange-900 text-white">Brickhouse</div>
            <span class="ml-1">
                Hello, world!
            </span>
        </div>
        HTML);

        if (!$this->directory) {
            $this->directory = text(
                label: 'What is the name of the project?',
                placeholder: 'E.g. example-app',
                required: 'Project name is required.',
                validate: function (string $value) {
                    if (!preg_match('/^[\w-]+$/', $value)) {
                        return 'The name may only contain letters, numbers, dashes and underscores.';
                    }

                    if (!$this->force && $this->verifyProjectDoesntExist($value)) {
                        return 'Application already exists.';
                    }
                }
            );
        }

        if (!$this->force && $this->verifyProjectDoesntExist($this->directory)) {
            $this->error('Application already exists.');
            return 1;
        }

        if ($this->apiOnly === null) {
            $this->apiOnly = confirm(
                label: 'Is the project an API-only project?',
                initial: false
            );
        }

        if ($this->initializeGit === null && Process::execute('git --version')->isSuccessful()) {
            $this->initializeGit = confirm(
                label: 'Would you like to initialize a Git repository?',
                initial: false
            );
        }

        if ($this->usePest === null) {
            $this->usePest = confirm(
                label: 'Which testing framework should be installed?',
                initial: true,
                active: 'Pest',
                inactive: 'PHPunit',
            );
        }

        if (($status = $this->build()) !== 0) {
            return $status;
        }

        $this->writeHtml(<<<HTML
        <div class="ml-2 mt-1">
            <div>
                🔥 Application created successfully. You can start your development server with: <br />
                <span class="text-gray ml-4 mr-1">➜</span><b class="font-bold">cd ./{$this->directory}</b><br />
                <span class="text-gray ml-4 mr-1">➜</span><b class="font-bold">php brickhouse serve</b>
            </div>

            <div class="my-1">
                Want to see more? You can learn more from our <a href=https://brickhouse-php.github.io/getting-started/introduction/>documentation</a>.
                <b>We're happy to have you!</b>
            </div>
        </div>
        HTML);

        return 0;
    }

    /**
     * Build the target project.
     */
    protected function build(): int
    {
        $commands = [
            $this->composerBinary . " create-project brickhouse/brickhouse \"{$this->directory}\" dev-main --remove-vcs --prefer-dist --no-scripts --repository '{\"type\": \"path\", \"url\": \"/Users/max/Documents/Projects/brickhouse/brickhouse\"}' --ansi",
            $this->composerBinary . " run post-root-package-install -d \"{$this->directory}\"",
        ];

        // Delete the existing directory, if `force` is `true`.
        if ($this->force && $this->verifyProjectDoesntExist($this->directory)) {
            array_unshift($commands, "rm -rf \"{$this->directory}\"");
        }

        $this->info("Creating project '{$this->directory}'...");

        foreach ($commands as $command) {
            if ($this->runCommand($command) !== 0) {
                break;
            }
        }

        if ($this->apiOnly) {
            $this->info("Updating project to be API-only...");

            // Update the config the be API-only.
            $this->updateFile(
                'api_only: false',
                'api_only: true',
                path($this->directory, 'config', 'app.config.php')
            );

            // Delete all views in the application, as they aren't needed for APIs.
            foreach (glob(path($this->directory, 'resources', 'views', '*', '*.html.php')) as $view) {
                unlink($view);
            }

            rmdir(path($this->directory, 'resources', 'views', 'components'));
            rmdir(path($this->directory, 'resources', 'views', 'layouts'));

            // Remove `assets` folder.
            foreach (glob(path($this->directory, 'assets', '*')) as $asset) {
                unlink($asset);
            }

            rmdir(path($this->directory, 'assets'));

            // Remove NPM and other JS files.
            unlink(path($this->directory, 'package.json'));
            unlink(path($this->directory, 'tailwind.config.js'));

            $this->stubFile("routes.api.php", path($this->directory, 'routes', 'app.php'));
        }

        if ($this->usePest) {
            $this->info("Installing Pest...");
            $this->installPest();
        }

        if (@is_file(path($this->directory, 'package.json'))) {
            $this->info("Installing npm packages...");
            $this->runCommand('npm install', $this->directory);
        }

        if (!$this->apiOnly) {
            $this->info("Building assets...");
            $this->runCommand('php brickhouse build', $this->directory);
        }

        if ($this->initializeGit) {
            $this->info("Initializing Git repository...");
            $this->initializeGitRepository();
        }

        return 0;
    }

    /**
     * Initializes a new Git repository in the target directory.
     *
     * @return void
     */
    protected function initializeGitRepository(): void
    {
        $branchName = $this->getDefaultBranchName();

        $commands = [
            "git init -q",
            "git add .",
            "git commit -m \"feat: create new Brickhouse application\"",
            "git branch -M {$branchName}",
        ];

        foreach ($commands as $command) {
            if ($this->runCommand($command, $this->directory) !== 0) {
                break;
            }
        }
    }

    /**
     * Gets the default branch name from the Git configuration. If none could be found, returns `main`.
     *
     * @return string
     */
    private function getDefaultBranchName(): string
    {
        $result = Process::execute('git config --global init.defaultBranch');

        if ($result->isSuccessful()) {
            return trim($result->stdout);
        }

        return 'main';
    }

    /**
     * Installs Pest in the target project.
     *
     * @return void
     */
    protected function installPest(): void
    {
        $commands = [
            $this->composerBinary . " remove phpunit/phpunit --dev --no-update",
            $this->composerBinary . " require pestphp/pest --dev --no-update",
            $this->composerBinary . " update",
            $this->phpBinary . " ./vendor/bin/pest --init",
        ];

        $env = [
            'PEST_NO_SUPPORT' => 'true',
        ];

        foreach ($commands as $command) {
            if ($this->runCommand($command, $this->directory, $env) !== 0) {
                break;
            }
        }

        $testDirectory = path($this->directory, 'tests');

        $this->stubFile('pest/Unit.php', path($testDirectory, 'Unit', 'ExampleTest.php'));
        $this->stubFile('pest/Feature.php', path($testDirectory, 'Feature', 'ExampleTest.php'));
        $this->stubFile('pest/TestCase.php', path($testDirectory, 'TestCase.php'));
        $this->stubFile('pest/Pest.php', path($testDirectory, 'Pest.php'));
    }

    /**
     * Verifies that the project directory `$target` either doesn't exist or is empty.
     *
     * @param string $target
     *
     * @return bool
     */
    protected function verifyProjectDoesntExist(string $target): bool
    {
        return is_dir($target);
    }

    /**
     * Runs the given command in the given working directory.
     *
     * @param string                    $command        Shell command to execute.
     * @param null|string               $cwd            Working directory for the command.
     * @param null|array<string,string> $env            Environment variables for the command.
     *
     * @return int          The exit code of the command.
     */
    protected function runCommand(string $command, null|string $cwd = null, null|array $env = null): int
    {
        if (!$this->quiet) {
            $this->writeln("> {$command}");
        }

        $result = Process::execute($command, $cwd, $env, callback: function (int $type, string $value) {
            if ($this->quiet) {
                return;
            }

            $this->write($value);
        });

        if ($result->exitCode !== 0) {
            $this->error("Command returned with exit code {$result->exitCode}: {$command}");
            $this->write($result->stderr);
        }

        return $result->exitCode;
    }

    /**
     * Replaces the given string(s) in the file.
     *
     * @param string|list<string>   $search
     * @param string|list<string>   $replacement
     * @param string                $file
     *
     * @return void
     */
    protected function updateFile(array|string $search, array|string $replacement, string $file): void
    {
        $existing = file_get_contents($file);
        $update = str_replace($search, $replacement, $existing);

        file_put_contents($file, $update);
    }

    /**
     * Replaces the given file with a stub.
     *
     * @param string    $stub
     * @param string    $destination
     *
     * @return void
     */
    protected function stubFile(string $stub, string $destination): void
    {
        $stubPath = path(__DIR__, '..', 'stubs', $stub);

        $directory = pathinfo($destination, PATHINFO_DIRNAME);
        if (!@is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        file_put_contents(
            $destination,
            file_get_contents($stubPath)
        );
    }
}
