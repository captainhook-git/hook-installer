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
 * @link    https://github.com/captainhook-git/hook-installer
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
     * @var \CaptainHook\HookInstaller\DotGit
     */
    private DotGit $dotGit;

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
        $this->io->write('<info>CaptainHook Hook Installer</info>');
        if ($this->shouldExecutionBeSkipped()) {
            return;
        }
        $this->detectConfiguration();
        $this->detectCaptainExecutable();
        $this->detectGitDir();

        if ($this->dotGit->isAdditionalWorktree()) {
            $this->displayGitWorktreeInfo();
            return;
        }
        if (!file_exists($this->executable)) {
            $this->displayNoExecutableInfo();
            return;
        }

        if (!file_exists($this->configuration)) {
            $this->displayNoConfigInfo();
            return;
        }

        $this->io->write('  - Detect executable: <comment>' . $this->executable . '</comment>');
        $this->io->write('  - Detect configuration: <comment>' . $this->relativePath($this->configuration) . '</comment>');
        $this->io->write('  - Install hooks: ', false);
        $this->install();
        $this->io->write('<comment>done</comment>');
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
        try {
            $this->dotGit = DotGit::searchInPath(getcwd());
        } catch (RuntimeException $e) {
            throw new RuntimeException($this->pluginErrorMessage($e->getMessage()));
        }
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
     * Should we actually execute the plugin
     *
     * @return bool
     */
    private function shouldExecutionBeSkipped(): bool
    {
        if ($this->isPluginDisabled()) {
            $this->io->write('  <comment>plugin is disabled</comment>');
            return true;
        }
        if (getenv('CI') === 'true') {
            $this->io->write('  <comment>disabling plugin due to CI-environment</comment>');
            return true;
        }
        return false;
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
    private function forceInstallConfig(): bool
    {
        $extra = $this->composer->getPackage()->getExtra();
        return ($extra['captainhook']['force-install'] ?? false) || getenv('CAPTAINHOOK_FORCE_INSTALL') === 'true';
    }

    /**
     * Is a force installation configured
     *
     * @return bool
     */
    private function onlyEnabledConfig(): bool
    {
        $extra = $this->composer->getPackage()->getExtra();
        return $extra['captainhook']['only-enabled'] ?? false;
    }

    /**
     * Install hooks to your .git/hooks directory
     */
    private function install(): void
    {
        try {
            $installer = new Installer($this->executable, $this->configuration, $this->dotGit->gitDirectory());
            $installer->install($this->io, $this->forceInstallConfig(), $this->onlyEnabledConfig());
        } catch (\Exception $e) {
            throw new RuntimeException($this->pluginErrorMessage($e->getMessage()));
        }
    }

    /**
     * Displays some message to make the user aware that the plugin is doing nothing because we are in a worktree
     *
     * @return void
     */
    private function displayGitWorktreeInfo(): void
    {
        $this->io->write('  <comment>ARRRRR! We ARRR in a worktree, install is skipped!</comment>');
    }

    /**
     * Displays a helpful message to the user if the captainhook executable could not be found
     *
     * @return void
     */
    private function displayNoExecutableInfo(): void
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
            '  If you are uninstalling CaptainHook, we are sad seeing you go, ' . PHP_EOL .
            '  but we would appreciate your feedback on your experience.' . PHP_EOL .
            '  Just go to https://github.com/captainhook-git/captainhook/issues to leave your feedback' . PHP_EOL .
            PHP_EOL
        );
    }

    /**
     * Displays a helpful message to the user if the captainhook configuration could not be found
     *
     * @return void
     */
    private function displayNoConfigInfo(): void
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

    /**
     * Returns relative path
     *
     * @param string $path
     * @return string
     */
    private function relativePath(string $path): string
    {
        return str_replace(getcwd() . '/', '', $path);
    }
}
