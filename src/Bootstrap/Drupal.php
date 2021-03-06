<?php

namespace Drupal\Console\Bootstrap;

use Doctrine\Common\Annotations\AnnotationRegistry;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Filesystem\Filesystem;
use Drupal\Console\Core\Style\DrupalStyle;
use Drupal\Console\Core\Utils\ArgvInputReader;
use Drupal\Console\Core\Bootstrap\DrupalConsoleCore;

class Drupal
{
    protected $autoload;
    protected $root;
    protected $appRoot;

    /**
     * Drupal constructor.
     *
     * @param $autoload
     * @param $root
     * @param $appRoot
     */
    public function __construct($autoload, $root, $appRoot)
    {
        $this->autoload = $autoload;
        $this->root = $root;
        $this->appRoot = $appRoot;
    }

    public function boot($debug)
    {
        $output = new ConsoleOutput();
        $input = new ArrayInput([]);
        $io = new DrupalStyle($input, $output);

        if (!class_exists('Drupal\Core\DrupalKernel')) {
            $io->error('Class Drupal\Core\DrupalKernel do not exists.');
            $drupal = new DrupalConsoleCore($this->root, $this->appRoot);
            return $drupal->boot();
        }

        try {
            // Add support for Acquia Dev Desktop sites on Mac OS X
            // @TODO: Check if this condition works in Windows
            $devDesktopSettingsDir = getenv('HOME') . "/.acquia/DevDesktop/DrupalSettings";
            if (file_exists($devDesktopSettingsDir)) {
                $_SERVER['DEVDESKTOP_DRUPAL_SETTINGS_DIR'] = $devDesktopSettingsDir;
            }
            $argvInputReader = new ArgvInputReader();

            if ($debug) {
                $io->writeln('➤ Creating request');
            }
            if ($argvInputReader->get('uri')) {
                $uri = $argvInputReader->get('uri');
                if (substr($uri, -1) != '/') {
                    $uri .= '/';
                }
                $uri .= 'index.php';
                $request = Request::create($uri, 'GET', [], [], [], ['SCRIPT_NAME' => $this->appRoot . '/index.php']);
            } else {
                $request = Request::createFromGlobals();
            }

            if ($debug) {
                $io->writeln("\r\033[K\033[1A\r<info>✔</info>");
                $io->writeln('➤ Deleting local PHP files');
            }
            $fs = new Filesystem();
            $fs ->remove($this->appRoot.'/sites/default/files/php/');

            if ($debug) {
                $io->writeln("\r\033[K\033[1A\r<info>✔</info>");
                $io->writeln('➤ Creating Drupal kernel');
            }
            $drupalKernel = DrupalKernel::createFromRequest(
                $request,
                $this->autoload,
                'prod',
                false
            );
            if ($debug) {
                $io->writeln("\r\033[K\033[1A\r<info>✔</info>");
                $io->writeln('➤ Providing dynamic services');
            }
            $drupalKernel->addServiceModifier(
                new DrupalServiceModifier(
                    $this->root,
                    $this->appRoot,
                    'drupal.command',
                    'drupal.generator'
                )
            );
            if ($debug) {
                $io->writeln("\r\033[K\033[1A\r<info>✔</info>");
                $io->writeln('➤ Rebuilding container');
            }
            $drupalKernel->invalidateContainer();
            $drupalKernel->rebuildContainer();
            $drupalKernel->boot();

            if ($debug) {
                $io->writeln("\r\033[K\033[1A\r<info>✔</info>");
            }

            $container = $drupalKernel->getContainer();
            $container->set('console.root', $this->root);

            AnnotationRegistry::registerLoader([$this->autoload, "loadClass"]);

            $configuration = $container->get('console.configuration_manager')
                ->loadConfiguration($this->root)
                ->getConfiguration();

            $container->get('console.translator_manager')
                ->loadCoreLanguage(
                    $configuration->get('application.language'),
                    $this->root
                );

            $container->get('console.renderer')
                ->setSkeletonDirs(
                    [
                        $this->root.DRUPAL_CONSOLE.'/templates/',
                        $this->root.DRUPAL_CONSOLE_CORE.'/templates/'
                    ]
                );

            return $container;
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            $drupal = new DrupalConsoleCore($this->root, $this->appRoot);
            $container = $drupal->boot();
            $container->set('class_loader', $this->autoload);
            return $container;
        }
    }
}
