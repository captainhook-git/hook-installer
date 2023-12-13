<?php
/**
 * This file is part of CaptainHook.
 *
 * (c) Sebastian Feldmann <sf@sebastian.feldmann.info>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace CaptainHook\HookInstaller;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use RuntimeException;

/**
 * Class ComposerPlugin
 *
 * @package CaptainHook\Plugin
 * @author  Sebastian Feldmann <sf@sebastian-feldmann.info>
 * @link    https://github.com/captainhookphp/hook-installer
 */
class ComposerPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * Composer instance
     *
     * @var \Composer\Composer
     */
    private Composer $composer;

    /**
     * Composer IO instance
     *
     * @var \Composer\IO\IOInterface
     */
    private IOInterface $io;

    /**
     * Path to the captainhook executable
     *
     * @var string
     */
    private string $executable;

    /**
     * Path to the captainhook configuration file
     *
     * @var string
     */
    private string $configuration;

    /**
     * Path to the .git directory
     *
     * @var string
     */
    private string $gitDirectory;

    /**
     * @var bool
     */
    private bool $isWorktree = false;

    /**
     * Activate the plugin
     *
     * @param  \Composer\Composer       $composer
     * @param  \Composer\IO\IOInterface $io
     * @return void
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io       = $io;
    }

    /**
     * Remove any hooks from Composer
     *
     * This will be called when a plugin is deactivated before being
     * uninstalled, but also before it gets upgraded to a new version
     * so the old one can be deactivated and the new one activated.
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        // Do nothing currently
    }

    /**
     * Prepare the plugin to be uninstalled
     *
     * This will be called after deactivate.
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
        // Do nothing currently
    }

    /**
     * Make sure the installer is executed after the autoloader is created
     *
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'installHooks',
            ScriptEvents::POST_UPDATE_CMD  => 'installHooks'
         ];
    }

    /**
     * Run the installer
     *
     * @param  \Composer\Script\Event $event
     * @return void
     * @throws \Exception
     */
    public function installHooks(Event $event): void
    {
        $this->io->write('<info>CaptainHook HookInstaller</info>');

        if ($this->isPluginDisabled()) {
            $this->io->write('  <comment>plugin is disabled</comment>');
            return;
        }

        if (getenv('CI') === 'true') {
            $this->io->write('  <comment>disabling plugin due to CI-environment</comment>');
            return;
        }

        $this->detectConfiguration();
        $this->detectGitDir();
        if ($this->isWorktree) {
            $this->io->write('  <comment>ARRRRR! We ARRR in a worktree, install is skipped!</comment>');
            return;
        }
        $this->detectCaptainExecutable();

        if (!file_exists($this->executable)) {
            $this->writeNoExecutableHelp();
            return;
        }
        if (!file_exists($this->configuration)) {
            $this->writeNoConfigHelp();
            return;
        }
        $this->install();
    }

    /**
     * Install hooks to your .git/hooks directory
     */
    private function install(): void
    {
        // Respect composer CLI settings
        $ansi        = $this->io->isDecorated() ? ' --ansi' : ' --no-ansi';
        $interaction = ' --no-interaction';
        $executable  = escapeshellarg($this->executable);

        // captainhook config and repository settings
        $configuration  = ' -c ' . escapeshellarg($this->configuration);
        $repository     = ' -g ' . escapeshellarg($this->gitDirectory);
        $forceOrSkip    = $this->isForceInstall() ? ' -f' : ' -s';

        // sub process settings
        $cmd   = PHP_BINARY . ' '  . $executable . ' install'
               . $ansi . $interaction . $forceOrSkip
               . $configuration . $repository;
        $pipes = [];
        $spec  = [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ];

        $process = @proc_open($cmd, $spec, $pipes);

        if ($this->io->isVerbose()) {
            $this->io->write('Running process : ' . $cmd);
        }
        if (!is_resource($process)) {
            throw new RuntimeException($this->pluginErrorMessage('no-process'));
        }

        // Loop on process until it exits normally.
        do {
            $status = proc_get_status($process);
        } while ($status && $status['running']);
        $exitCode = $status['exitcode'] ?? -1;
        proc_close($process);
        if ($exitCode !== 0) {
            $this->io->writeError($this->pluginErrorMessage('installation process failed'));
        }
    }

    /**
     * Return path to the CaptainHook configuration file
     *
     * @return void
     */
    private function detectConfiguration(): void
    {
        $extra               = $this->composer->getPackage()->getExtra();
        $this->configuration = getcwd() . '/' . ($extra['captainhook']['config'] ?? 'captainhook.json');
    }

    /**
     * Search for the git repository to store the hooks in

     * @return void
     * @throws \RuntimeException
     */
    private function detectGitDir(): void
    {
        $path = getcwd();

        while (file_exists($path)) {
            $possibleGitDir = $path . '/.git';
            if (is_dir($possibleGitDir)) {
                $this->gitDirectory = $possibleGitDir;
                return;
            } elseif (is_file($possibleGitDir)) {
                $gitfile = file($possibleGitDir);
                $match = [];
                preg_match('#^gitdir: (?<gitdir>[a-zA-Z/\.]*\.git)#', $gitfile[0] ?? '', $match);
                $dir = $match['gitdir'] ?? '';
                if (is_dir($dir)) {
                    $this->isWorktree = true;
                }

            }

            // if we checked the root directory already, break to prevent endless loop
            if ($path === dirname($path)) {
                break;
            }

            $path = \dirname($path);
        }
        if ($this->isWorktree) {
            return;
        }
        throw new RuntimeException($this->pluginErrorMessage('git directory not found'));
    }

    /**
     * Try to find the captainhook executable
     *
     * Will check the `extra` config otherwise it will use the composer `bin` directory.
     */
    private function detectCaptainExecutable(): void
    {
        $extra = $this->composer->getPackage()->getExtra();
        if (isset($extra['captainhook']['exec'])) {
            $this->executable = $extra['captainhook']['exec'];
            return;
        }

        $this->executable = (string) $this->composer->getConfig()->get('bin-dir') . '/captainhook';
    }

    /**
     * Check if the plugin is disabled
     *
     * @return bool
     */
    private function isPluginDisabled(): bool
    {
        $extra = $this->composer->getPackage()->getExtra();
        return ($extra['captainhook']['disable-plugin'] ?? false) || getenv('CAPTAINHOOK_DISABLE') === 'true';
    }

    /**
     * Is a force installation configured
     *
     * @return bool
     */
    private function isForceInstall(): bool
    {
        $extra = $this->composer->getPackage()->getExtra();
        return ($extra['captainhook']['force-install'] ?? false) || getenv('CAPTAINHOOK_FORCE_INSTALL') === 'true';
    }

    /**
     * Displays a helpful message to the user if the captainhook executable could not be found
     *
     * @return void
     */
    private function writeNoExecutableHelp(): void
    {
        $this->io->write(
            '  <comment>CaptainHook executable not found</comment>' . PHP_EOL .
            PHP_EOL .
            '  Make sure you have installed <info>CaptainHook</info> .' . PHP_EOL .
            '  If you installed the Cap\'n to a custom location you have to configure the path ' .PHP_EOL .
            '  to your CaptainHook executable using Composers \'extra\' config. e.g.' . PHP_EOL .
            PHP_EOL . '<comment>' .
            '    "extra": {' . PHP_EOL .
            '        "captainhook": {' . PHP_EOL .
            '            "exec": "tools/captainhook.phar' . PHP_EOL .
            '        }' . PHP_EOL .
            '    }' . PHP_EOL .
            '</comment>' . PHP_EOL .
            '  If you are uninstalling CaptainHook, we are sad seeing you go, ' .
            '  but we would appreciate your feedback on your experience.' . PHP_EOL .
            '  Just go to https://github.com/captainhookphp/captainhook/issues to leave your feedback' . PHP_EOL .
            PHP_EOL
        );
    }

    /**
     * Displays a helpful message to the user if the captainhook configuration could not be found
     *
     * @return void
     */
    private function writeNoConfigHelp(): void
    {
        $this->io->write(
            '  <comment>CaptainHook configuration not found</comment>' . PHP_EOL .
            PHP_EOL .
            '  If your CaptainHook configuration is not named <info>captainhook.json</info> or is not' . PHP_EOL .
            '  located in your repository root you have to configure the path to your' .PHP_EOL .
            '  CaptainHook configuration using Composers \'extra\' config. e.g.' . PHP_EOL .
            PHP_EOL .
            '    <comment>"extra": {' . PHP_EOL .
            '        "captainhook": {' . PHP_EOL .
            '            "config": "config/hooks.json' . PHP_EOL .
            '        }' . PHP_EOL .
            '    }</comment>' . PHP_EOL .
            PHP_EOL
        );
    }

    /**
     * Creates a nice formatted error message
     *
     * @param  string $reason
     * @return string
     */
    private function pluginErrorMessage(string $reason): string
    {
        return 'Shiver me timbers! CaptainHook could not install yer git hooks! (' . $reason . ')';
    }
}
