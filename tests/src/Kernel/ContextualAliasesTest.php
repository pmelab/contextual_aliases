<?php

namespace Drupal\Tests\contextual_aliases\Kernel;

use Drupal\contextual_aliases\AliasContextResolverInterface;
use Drupal\contextual_aliases\ContextualAliasStorage;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Path\AliasWhitelistInterface;
use Drupal\KernelTests\KernelTestBase;
use Prophecy\Argument;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Kernel tests for contextual alias storage.
 */
class ContextualAliasesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = ['system', 'contextual_aliases'];

  /**
   * The alias storage.
   *
   * @var \Drupal\Core\Path\AliasStorageInterface
   */
  protected $aliasStorage;

  /**
   * The mocked instance of a context resolver.
   *
   * @var AliasContextResolverInterface
   */
  protected $resolverInstance;

  /**
   * The resolvers prophecy.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $resolver;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    $this->resolver = $this->prophesize(AliasContextResolverInterface::class);
    $this->resolverInstance = $this->resolver->reveal();

    $definition = new Definition(get_class($this->resolverInstance));
    $definition->setFactory([$this, 'getResolverInstance']);

    $definition->addTag('alias_context_resolver');
    $container->addDefinitions([
      'test.alias_context_resolver' => $definition,
    ]);

  }

  /**
   * Factory method to get the mocked AliasContextResolver.
   *
   * @return \Drupal\contextual_aliases\AliasContextResolverInterface
   */
  public function getResolverInstance() {
    return $this->resolverInstance;
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $whitelist = $this->prophesize(AliasWhitelistInterface::class);
    $whitelist->get(Argument::any())->willReturn(TRUE);
    $this->container->set('path.alias_whitelist', $whitelist->reveal());
    $this->installSchema('system', 'url_alias');
    module_load_include('install', 'contextual_aliases', 'contextual_aliases');
    contextual_aliases_install();
  }

  public function testServiceInjection() {
    $storage = $this->container->get('path.alias_storage');
    $this->assertInstanceOf(ContextualAliasStorage::class, $storage);
  }

  public function testNoContext() {
    $this->resolver->getCurrentContext()->willReturn(NULL);
    $this->resolver->resolveContext(Argument::any())->willReturn(NULL);

    $storage = $this->container->get('path.alias_storage');
    $storage->save('/a', '/b', 'en');

    $manager = $this->container->get('path.alias_manager');

    $this->assertEquals('/a', $manager->getPathByAlias('/b'));
    $this->assertEquals('/b', $manager->getAliasByPath('/a'));
  }

  public function testInactiveContext() {
    $this->resolver->resolveContext('/a')->willReturn('a');

    $storage = $this->container->get('path.alias_storage');
    $storage->save('/a', '/b', 'en');

    $manager = $this->container->get('path.alias_manager');

    // If there is no context, the alias should not be found.
    $this->resolver->getCurrentContext()->willReturn(NULL);

    // If the current context is NULL, all aliases are taken into account.
    $this->assertEquals('/a', $manager->getPathByAlias('/b'));
    $this->assertEquals('/b', $manager->getAliasByPath('/a'));
  }

  public function testActiveContext() {
    $this->resolver->resolveContext('/a')->willReturn('a');

    $storage = $this->container->get('path.alias_storage');
    $storage->save('/a', '/b', 'en');

    $manager = $this->container->get('path.alias_manager');

    // Now the proper aliased path for this context should be returned.
    $this->resolver->getCurrentContext()->willReturn('a');

    // For unknown aliases, getPathByAlias will return the alias itself.
    $this->assertEquals('/a', $manager->getPathByAlias('/b'));
    // There should be now
    $this->assertEquals('/b', $manager->getAliasByPath('/a'));
  }

}
