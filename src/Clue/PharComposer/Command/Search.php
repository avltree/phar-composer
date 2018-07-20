<?php


namespace Clue\PharComposer\Command;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Packagist\Api\Client;
use Packagist\Api\Result\Result;
use Packagist\Api\Result\Package;
use Packagist\Api\Result\Package\Version;
use Clue\PharComposer\Phar\Packager;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

class Search extends Command
{
    protected function configure()
    {
        $this->setName('search')
             ->setDescription('Interactive search for project name')
             ->addArgument('name', InputArgument::OPTIONAL, 'Project name or path', null);
    }

    protected function select(InputInterface $input, OutputInterface $output, $label, array $choices, $abortable = null)
    {
        /* @var $dialog QuestionHelper */
        $dialog = $this->getHelper('question');

        if (!$choices) {
            $output->writeln('<error>No matching packages found</error>');
            return;
        }

        // TODO: skip dialog, if exact match

        if ($abortable) {
            array_unshift($choices, 'Abort');
        }

        $question = new ChoiceQuestion($label, array_values($choices), 0);
        $index = $dialog->ask($input, $output, $question);

        if ($index == 0) {
            return null;
        }

        $indices = array_keys($choices);
        return $indices[$index - 1];
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $packager = new Packager();
        $packager->setOutput($output);
        $packager->coerceWritable();

        $dialog = $this->getHelper('question');
        /* @var $dialog QuestionHelper */

        $name = $input->getArgument('name');

        do {
            if ($name === null) {
                // ask for input
                $name = $dialog->ask($input, $output, new Question('Enter (partial) project name > '));
            } else {
                $output->writeln('Searching for <info>' . $name . '</info>...');
            }

            $packagist = new Client();

            $choices = array();
            foreach ($packagist->search($name) as $package) {
                /* @var $package Result */

                $label = str_pad($package->getName(), 39) . ' ';
                $label = str_replace($name, '<info>' . $name . '</info>', $label);
                $label .= $package->getDescription();

                $label .= ' (⤓' . $package->getDownloads() . ')';

                $choices[$package->getName()] = $label;
            }

            $name = $this->select($input, $output, 'Select matching package', $choices, 'Start new search');
        } while ($name === null);

        $output->writeln('Selected <info>' . $name . '</info>, listing versions...');

        $package = $packagist->get($name);
        /* @var $package Package */

        $choices = array();
        foreach ($package->getVersions() as $version) {
            /* @var $version Version */

            $label = $version->getVersion();

            $bin = $version->getBin();
            if ($bin === null) {
                $label .= ' (<error>no executable bin</error>)';
            } else {
                $label .= ' (☑ executable bin)';
            }

            $choices[$version->getVersion()] = $label;
        }

        $version = $this->select($input, $output, 'Select available version', $choices);

        $action = $this->select(
            $input,
            $output,
            'Action',
            array(
                'build'   => 'Build project',
                'install' => 'Install project system-wide'
            ),
            'Quit'
        );

        if ($action === null) {
            return;
        }

        $pharer = $packager->getPharer($name, $version);

        if ($action === 'install') {
            $path = $packager->getSystemBin($pharer);
            $packager->install($pharer, $path);
        } else {
            $pharer->build();
        }
    }
}
