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
   * The alias manager.
   *
   * @var \Drupal\Core\Path\AliasManagerInterface
   */
  protected $manager;

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

    $this->resolver->resolveContext('/a')->willReturn('one');
    $this->resolver->resolveContext('/b')->willReturn('two');
    $this->resolver->resolveContext('/c')->willReturn(NULL);
    $this->resolver->resolveContext('/d')->willReturn(NULL);
    $this->resolver->resolveContext('/e')->willReturn('two');

    $storage = $this->container->get('path.alias_storage');
    $storage->save('/a', '/A', 'en');
    $storage->save('/b', '/A', 'en');
    $storage->save('/b', '/B', 'en');
    $storage->save('/c', '/C', 'en');
    $storage->save('/d', '/one/D', 'en');
    $storage->save('/e', '/one/E', 'en');

    $this->manager = $this->container->get('path.alias_manager');
    $this->aliasStorage = $this->container->get('path.alias_storage');
  }

  /**
   * Test if service is injected properly.
   */
  public function testServiceInjection() {
    $storage = $this->container->get('path.alias_storage');
    $this->assertInstanceOf(ContextualAliasStorage::class, $storage);
  }

  /**
   * Test for vanilla aliases without contexts.
   */
  public function testNoContextSimpleAlias() {
    $this->resolver->getCurrentContext()->willReturn(NULL);
    $this->assertEquals('/c', $this->manager->getPathByAlias('/C'));
    $this->assertEquals('/C', $this->manager->getAliasByPath('/c'));
  }

  /**
   * Test contextual aliases outside of global context.
   */
  public function testNoContextContextualAlias() {
    $this->resolver->getCurrentContext()->willReturn(NULL);
    $this->assertEquals('/b', $this->manager->getPathByAlias('/A'));
    $this->assertEquals('/A', $this->manager->getAliasByPath('/a'));
  }

  /**
   * Test contextual aliases within a matching global context.
   */
  public function testContextMatchingAlias() {
    $this->resolver->getCurrentContext()->willReturn('one');
    $this->assertEquals('/a', $this->manager->getPathByAlias('/A'));
    $this->assertEquals('/A', $this->manager->getAliasByPath('/a'));
  }

  /**
   * Test contextual aliases within a different global context.
   */
  public function testContextDifferentMatchingAlias() {
    $this->resolver->getCurrentContext()->willReturn('two');
    $this->assertEquals('/b', $this->manager->getPathByAlias('/A'));
    $this->assertEquals('/A', $this->manager->getAliasByPath('/a'));
  }

  /**
   * Test contextual aliases within a different global context.
   */
  public function testContextNotMatchingAlias() {
    $this->resolver->getCurrentContext()->willReturn('three');
    $this->assertEquals('/b', $this->manager->getPathByAlias('/A'));
    $this->assertEquals('/A', $this->manager->getAliasByPath('/a'));
  }

  /**
   * Test simple aliases within a defined global context.
   */
  public function testContextSimpleAlias() {
    $this->resolver->getCurrentContext()->willReturn('one');
    $this->assertEquals('/c', $this->manager->getPathByAlias('/C'));
    $this->assertEquals('/C', $this->manager->getAliasByPath('/c'));
  }

  /**
   * Test aliases that contain another context's prefix.
   */
  public function testNonContextualConflictingAlias() {
    $this->resolver->getCurrentContext()->willReturn(NULL);
    $this->assertEquals('/d', $this->manager->getPathByAlias('/one/D'));
    $this->assertEquals('/one/D', $this->manager->getAliasByPath('/d'));

    $this->resolver->getCurrentContext()->willReturn('one');
    $this->assertEquals('/d', $this->manager->getPathByAlias('/one/D'));
    $this->assertEquals('/one/D', $this->manager->getAliasByPath('/d'));
  }

  /**
   * Test contextual aliases that contain another context's prefix.
   */
  public function testContextualConflictingAlias() {
    $this->resolver->getCurrentContext()->willReturn(NULL);
    $this->assertEquals('/e', $this->manager->getPathByAlias('/one/E'));
    $this->assertEquals('/one/E', $this->manager->getAliasByPath('/e'));

    $this->resolver->getCurrentContext()->willReturn('one');
    $this->assertEquals('/two/E', $this->manager->getPathByAlias('/two/E'));
    $this->assertEquals('/e', $this->manager->getPathByAlias('/one/E'));
    $this->assertEquals('/one/E', $this->manager->getAliasByPath('/e'));

    $this->resolver->getCurrentContext()->willReturn('two');
    $this->assertEquals('/e', $this->manager->getPathByAlias('/one/E'));
    $this->assertEquals('/one/E', $this->manager->getAliasByPath('/e'));
  }

}
