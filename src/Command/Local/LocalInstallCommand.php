<?php
namespace Platformsh\Cli\Command\Local;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LocalInstallCommand extends CommandBase
{
    protected $hiddenInList = true;
    protected $local = true;

    protected function configure()
    {
        $this->setName('local:install')
             ->setDescription('Install or update CLI configuration files');
        $this->setHelp(<<<EOT
This command automatically installs shell configuration for the Platform.sh CLI,
adding autocompletion support and handy aliases. Bash and ZSH are supported.
EOT
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $homeDir = $this->getHomeDir();
        $configDir = $this->getConfigDir();

        $platformRc = file_get_contents(CLI_ROOT . '/platform.rc');
        if ($platformRc === false) {
            $this->stdErr->writeln(sprintf('Failed to read file: %s', CLI_ROOT . '/platform.rc'));
            return 1;
        }

        $platformRcDestination = $configDir . DIRECTORY_SEPARATOR . 'platform.rc';
        if (file_put_contents($platformRcDestination, $platformRc) === false) {
            $this->stdErr->writeln(sprintf('Failed to write file: %s', $platformRcDestination));
            return 1;
        }

        $this->stdErr->writeln(sprintf('Successfully copied CLI configuration to: %s', $platformRcDestination));

        if (!$shellConfigFile = $this->findShellConfigFile($homeDir)) {
            $this->stdErr->writeln('Failed to find a shell configuration file.');
            return 1;
        }

        $this->stdErr->writeln(sprintf('Reading shell configuration file: %s', $shellConfigFile));

        $currentShellConfig = file_get_contents($shellConfigFile);
        if (strpos($currentShellConfig, $configDir . "/bin") !== false) {
            $this->stdErr->writeln(sprintf('Already configured: <info>%s</info>', $shellConfigFile));
            return 0;
        }

        $suggestedShellConfig = "export PATH=\"$configDir/bin:\$PATH\"" . PHP_EOL
            . '. ' . escapeshellarg($platformRcDestination) . " 2>/dev/null";

        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        if (!$questionHelper->confirm('Do you want to update the file automatically?', $input, $this->stdErr)) {
            $suggestedShellConfig = PHP_EOL
                . '# Platform.sh CLI configuration'
                . PHP_EOL
                . $suggestedShellConfig;

            $this->stdErr->writeln(sprintf('To set up the CLI, add the following lines to: <comment>%s</comment>', $shellConfigFile));
            $this->stdErr->writeln(preg_replace('/^/m', '  ', $suggestedShellConfig));
            return 1;
        }

        $newShellConfig = rtrim($currentShellConfig, PHP_EOL)
            . PHP_EOL . PHP_EOL
            . '# Automatically added by the Platform.sh CLI'
            . PHP_EOL . $suggestedShellConfig;

        copy($shellConfigFile, $shellConfigFile . '.cli.bak');

        if (!file_put_contents($shellConfigFile, $newShellConfig)) {
            $this->stdErr->writeln(sprintf('Failed to modify configuration file: %s', $shellConfigFile));
            return 1;
        }

        $shortPath = $shellConfigFile;
        if (getcwd() === dirname($shellConfigFile)) {
            $shortPath = basename($shellConfigFile);
        }
        if (strpos($shortPath, ' ')) {
            $shortPath = escapeshellarg($shortPath);
        }

        $this->stdErr->writeln("Updated successfully. Start a new terminal to use the new configuration.");
        $this->stdErr->writeln('Or to use it now, type:');
        $this->stdErr->writeln('  <info>source ' . $shortPath . '</info>');

        return 0;
    }

    /**
     * Finds a shell configuration file for the user.
     *
     * @param string $homeDir
     *   The user's home directory.
     *
     * @return string|false
     *   The absolute path to an existing shell config file, or false on
     *   failure.
     */
    protected function findShellConfigFile($homeDir)
    {
        $candidates = ['.zshrc', '.bashrc', '.bash_profile', '.profile'];
        $shell = str_replace('/bin/', '', getenv('SHELL'));
        if (!empty($shell)) {
            array_unshift($candidates, '.' . $shell . 'rc');
        }
        foreach ($candidates as $candidate) {
            if (file_exists($homeDir . DIRECTORY_SEPARATOR . $candidate)) {
                return $homeDir . DIRECTORY_SEPARATOR . $candidate;
            }
        }

        return false;
    }
}
