<?php

namespace Castor\Console;

use Castor\Console\Command\CompileCommand;
use Castor\Console\Command\DebugCommand;
use Castor\Console\Command\RepackCommand;
use Castor\Container;
use Castor\Event\AfterApplicationInitializationEvent;
use Castor\EventDispatcher;
use Castor\FunctionFinder;
use Castor\Helper\PathHelper;
use Castor\Helper\PlatformHelper;
use Castor\Monolog\Processor\ProcessProcessor;
use Monolog\Logger;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/** @internal */
class ApplicationFactory
{
    public static function create(): SymfonyApplication
    {
        $errorHandler = self::configureDebug();

        try {
            $rootDir = PathHelper::getRoot();
        } catch (\RuntimeException $e) {
            return new CastorFileNotFoundApplication($e);
        }

        $container = self::buildContainer();
        $container->getParameterBag()->add([
            'root_dir' => $rootDir,
            'cache_dir' => PlatformHelper::getCacheDirectory(),
            'event_dispatcher.event_aliases' => ConsoleEvents::ALIASES,
        ]);
        $container->compile();

        $container->set(ContainerInterface::class, $container);
        $container->set(ErrorHandler::class, $errorHandler);

        // @phpstan-ignore-next-line
        return $container->get(Application::class);
    }

    private static function configureDebug(): ErrorHandler
    {
        $errorHandler = ErrorHandler::register();

        AbstractCloner::$defaultCasters[self::class] = ['Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'];
        AbstractCloner::$defaultCasters[AfterApplicationInitializationEvent::class] = ['Symfony\Component\VarDumper\Caster\StubCaster', 'cutInternals'];

        return $errorHandler;
    }

    private static function buildContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $container->registerForAutoconfiguration(EventSubscriberInterface::class)
            ->addTag('kernel.event_subscriber')
        ;
        $container->addCompilerPass(new RegisterListenersPass());
        // from https://github.com/symfony/symfony/blob/6.4/src/Symfony/Bundle/FrameworkBundle/DependencyInjection/FrameworkExtension.php#L676-L685
        $container->registerAttributeForAutoconfiguration(AsEventListener::class, static function (ChildDefinition $definition, AsEventListener $attribute, \Reflector $reflector) {
            $tagAttributes = get_object_vars($attribute);
            if ($reflector instanceof \ReflectionMethod) {
                if (isset($tagAttributes['method'])) {
                    throw new \LogicException(sprintf('AsEventListener attribute cannot declare a method on "%s::%s()".', $reflector->class, $reflector->name));
                }
                $tagAttributes['method'] = $reflector->getName();
            }
            $definition->addTag('kernel.event_listener', $tagAttributes);
        });

        $phpLoader = new PhpFileLoader($container, new FileLocator());
        $instanceof = [];
        $configurator = new ContainerConfigurator($container, $phpLoader, $instanceof, __DIR__, __FILE__);
        self::configureContainer($configurator);

        return $container;
    }

    private static function configureContainer(ContainerConfigurator $c): void
    {
        $services = $c->services();

        $services
            ->defaults()
                ->autowire()
                ->autoconfigure()
                ->bind('string $rootDir', '%root_dir%')
                ->bind('string $cacheDir', '%cache_dir%')
            ->load('Castor\\', __DIR__ . '/../*')
            ->exclude([
                __DIR__ . '/../functions.php',
                __DIR__ . '/../functions-internal.php',
            ])
            ->set(CacheInterface::class, FilesystemAdapter::class)
                ->args([
                    '$directory' => '%cache_dir%',
                ])
            ->alias(CacheItemPoolInterface::class . '&' . CacheInterface::class, CacheInterface::class)
            ->set(HttpClientInterface::class)
                ->factory([HttpClient::class, 'create'])
                ->args([
                    '$defaultOptions' => [
                        'headers' => [
                            'User-Agent' => 'Castor/' . Application::VERSION,
                        ],
                    ],
                ])
            ->set(Logger::class)
                ->args([
                    '$name' => 'castor',
                    '$processors' => [
                        service(ProcessProcessor::class),
                    ],
                ])
            ->alias(LoggerInterface::class, Logger::class)
            ->alias(EventDispatcherInterface::class, EventDispatcher::class)
            ->alias('event_dispatcher', EventDispatcherInterface::class)
            ->set(Filesystem::class)
            ->set(AsciiSlugger::class)
            ->set(Container::class)
                ->public()
            ->set(ContainerInterface::class)
                ->synthetic()
            ->set(OutputInterface::class)
                ->synthetic()
                ->lazy()
            ->set(InputInterface::class)
                ->synthetic()
            ->set(SymfonyStyle::class)
            ->set(ErrorHandler::class)
                ->synthetic()
        ;

        $app = $services->set(Application::class, class_exists(\RepackedApplication::class) ? \RepackedApplication::class : null)
                ->public()
                ->args([
                    '$containerBuilder' => service(ContainerInterface::class),
                ])
                ->call('add', [service(DebugCommand::class)])
                ->call('setDispatcher', [service(EventDispatcherInterface::class)])
                ->call('setCatchErrors', [true])
        ;
        if (!class_exists(\RepackedApplication::class)) {
            $app
                ->call('add', [service(RepackCommand::class)])
                ->call('add', [service(CompileCommand::class)])
            ;
        }
    }
}
