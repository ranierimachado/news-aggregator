<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Kernel;

use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests maemgaba_core_update_11003() (missing entity definition records).
 */
#[Group('maemgaba_core')]
#[RunTestsInSeparateProcesses]
class EntityDefinitionRecordsTest extends PipelineKernelTestBase {

  /**
   * The key the SQL storage keeps field_card_count's table schema under.
   */
  private const SCHEMA_KEY = 'node.field_schema_data.field_card_count';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_handler')->loadInclude('maemgaba_core', 'install');
    // Kernel tests don't run the module installer, so record the config
    // entity type the way a real install does.
    $manager = $this->container->get('entity.definition_update_manager');
    if (!$manager->getEntityType('maemgaba_prompt')) {
      $manager->installEntityType($this->container->get('entity_type.manager')->getDefinition('maemgaba_prompt'));
    }
  }

  /**
   * Both records missing (a site that grew with the engine): restored.
   */
  public function testRestoresMissingRecords(): void {
    $event = $this->container->get('entity_type.manager')->getStorage('node')->create([
      'type' => 'event',
      'title' => 'Event',
      'field_card_count' => 7,
    ]);
    $event->save();

    $this->container->get('entity.last_installed_schema.repository')->deleteLastInstalledDefinition('maemgaba_prompt');
    $this->container->get('keyvalue')->get('entity.storage_schema.sql')->delete(self::SCHEMA_KEY);
    $this->container->get('entity_type.manager')->clearCachedDefinitions();

    $changes = $this->changeList();
    $this->assertSame(EntityDefinitionUpdateManagerInterface::DEFINITION_CREATED, $changes['maemgaba_prompt']['entity_type'] ?? NULL);
    $this->assertSame(EntityDefinitionUpdateManagerInterface::DEFINITION_UPDATED, $changes['node']['field_storage_definitions']['field_card_count'] ?? NULL);

    $message = maemgaba_core_update_11003();
    $this->assertStringContainsString('maemgaba_prompt', $message);
    $this->assertStringContainsString('field_card_count', $message);

    $changes = $this->changeList();
    $this->assertArrayNotHasKey('maemgaba_prompt', $changes);
    $this->assertArrayNotHasKey('field_card_count', $changes['node']['field_storage_definitions'] ?? []);
    $this->assertNotNull($this->container->get('keyvalue')->get('entity.storage_schema.sql')->get(self::SCHEMA_KEY));

    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $reloaded = $this->container->get('entity_type.manager')->getStorage('node')->load($event->id());
    $this->assertSame(7, (int) $reloaded->get('field_card_count')->value, 'existing data survives');

    $this->assertSame('Entity definitions already recorded.', maemgaba_core_update_11003(), 'second run is a no-op');
  }

  /**
   * Records present (fresh installs): nothing changes.
   */
  public function testNoOpWhenRecorded(): void {
    $schema = $this->container->get('keyvalue')->get('entity.storage_schema.sql')->get(self::SCHEMA_KEY);
    $this->assertNotNull($schema);
    $this->assertSame('Entity definitions already recorded.', maemgaba_core_update_11003());
    $this->assertSame($schema, $this->container->get('keyvalue')->get('entity.storage_schema.sql')->get(self::SCHEMA_KEY));
  }

  /**
   * A missing table is reported, not papered over.
   */
  public function testSkipsWhenTableMissing(): void {
    $this->container->get('keyvalue')->get('entity.storage_schema.sql')->delete(self::SCHEMA_KEY);
    $this->container->get('database')->schema()->dropTable('node_revision__field_card_count');

    $message = maemgaba_core_update_11003();
    $this->assertStringContainsStringIgnoringCase('left field_card_count alone', $message);
    $this->assertNull($this->container->get('keyvalue')->get('entity.storage_schema.sql')->get(self::SCHEMA_KEY));
  }

  /**
   * The entity definition change list, with fresh caches.
   */
  private function changeList(): array {
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    return $this->container->get('entity.definition_update_manager')->getChangeList();
  }

}
