<?php

namespace Drupal\pika_json\Controller;

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\islandora\IslandoraUtils;
use Drupal\media\MediaInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * Pika Controller providing JSON representations of nodes.
 * 
 * @author Chris Froese <chris@marmot.org>
 * @package Drupal\pika_json\Controller
 */
class PikaJsonController extends ControllerBase implements ContainerInjectionInterface
{
  protected EntityFieldManagerInterface $fieldManager;
  protected IslandoraUtils $islandoraUtils;
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /** @param array $fieldsToSkip These fields won't be included in JSON */
  private $fieldsToSkip = [
    "revision_log",
    "uid",
    "promote",
    "sticky",
    "default_langcode",
    "revision_default",
    "revision_translation_affected",
    "metatag",
    "path",
    "menu_link",
    "behavior_settings",
    "revision_log_message",
    "revision_user",
    "menu_link",
    "uuid",
    "revision_id",
    
  ];

  /**
   * Constructs a new PikaJsonController object.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $fieldManager
   *   The entity field manager service for retrieving field definitions.
   * @param \Drupal\pika_json\IslandoraUtils $islandoraUtils
   *   A utility service for working with Islandora-related entities.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator service used to get public URLs for file entities.
   */
  public function __construct(
    EntityFieldManagerInterface $fieldManager,
    IslandoraUtils $islandoraUtils,
    FileUrlGeneratorInterface $fileUrlGenerator
  ) {
    $this->fieldManager = $fieldManager;
    $this->islandoraUtils = $islandoraUtils;
    $this->fileUrlGenerator = $fileUrlGenerator;
  }

  /**
   * Creates an instance of the controller using the service container.
   *
   * This is part of the ContainerInjectionInterface and allows the controller
   * to be constructed with its required dependencies.
   *
   * @see https://drupalize.me/tutorial/concept-dependency-injection
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   *
   * @return static
   *   A new instance of the class with dependencies injected.
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('islandora.utils'),
      $container->get('file_url_generator')
    );
  }

  /**
   * Returns a JSON representation of a node and its related media.
   *
   * This is the main method of the class. This method serializes all relevant 
   * fields of the given node, including referenced taxonomy terms, paragraphs, 
   * and media entities. It handles flattening of simple field values and extracts 
   * media URLs and MIME types for file or image fields within the media entities.
   * 
   * Refer to pika_json.routing.yml.
   * 
   * Uses Islandora Utility class.
   * @see https://github.com/Islandora/islandora/blob/2.x/src/IslandoraUtils.php#L173
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node entity to be serialized to JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing structured data for the node and its media.
   */
  public function nodeJson(Node $node): JsonResponse
  {
    $data = [];
    // Process node fields
    foreach ($node->getFields() as $field_name => $field) {
      if ($field->isEmpty()) {
        $data[$field_name] = null;
        continue;
      }
      if (in_array($field_name, $this->fieldsToSkip)) {
        continue;
      }

      $def = $field->getFieldDefinition();
      $type = $def->getType();
      $target = $def->getSetting('target_type');
      // \Drupal::logger('pika_json')->debug('Processing field: @f (@t)', ['@f' => $field_name, '@t' => $type]);
      
      // Taxonomy
      if (($type === 'entity_reference' || $type === 'typed_relation') && $target === 'taxonomy_term') {
        $data[$field_name] = $this->parseTaxonomyField($field, $type === 'typed_relation');
      } 
      // Paragrahs
      elseif ($type === 'entity_reference_revisions' && $target === 'paragraph') {
        $items = array_map([$this, 'parseParagraph'], $field->referencedEntities());
        $data[$field_name] = count($items) === 1 ? $items[0] : $items;
      } 
      // Simple
      elseif (in_array($type, ['string', 'string_long', 'text', 'text_long', 'integer', 'boolean'])) {
        $values = array_column($field->getValue(), 'value');
        $data[$field_name] = count($values) === 1 ? $values[0] : $values;
      }
      // default
      else {
        $value = $field->getValue();
        if (is_array($value) && count($value) === 1 && is_array($value[0])) {
          $flat = $value[0];
          $data[$field_name] = count($flat) === 1 ? reset($flat) : $flat;
        } else {
          $data[$field_name] = $value;
        }
      }
    }

    // Attach Islandora media
    $media_items = $this->islandoraUtils->getMedia($node);
    $data['media'] = array_map([$this, 'parseMedia'], $media_items);

    // Attach child nodes only if parent is a Compound Object or Collection
    if ($node->hasField('field_model') && !$node->get('field_model')->isEmpty()) {
      
      $model_term = $node->get('field_model')->entity;
      
      if ($model_term && ($model_term->label() === 'Compound Object' || 
        $model_term->label() === 'Collection' )) {
        $children = $this->getChildNodes($node);
        $data['children'] = array_map([$this, 'parseChildNode'], $children);
      }
    }

    return new JsonResponse($data);
  }

  /**
   * Parses a paragraph entity into a structured array.
   *
   * This method serializes paragraph fields, handling nested paragraph references,
   * taxonomy references (e.g., `field_related_person`), and basic field types
   * like strings and integers. Complex field values are flattened when possible.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $paragraph
   *   The paragraph entity to parse.
   *
   * @return array
   *   An associative array representing the paragraph and its fields.
   */
  private function parseParagraph(Paragraph $paragraph): array
  {
    $result = ['id' => $paragraph->id(), 'type' => $paragraph->bundle()];
    foreach ($paragraph->getFields() as $name => $field) {
      if (in_array($name, $this->fieldsToSkip)) {
        continue;
      }
      if ($field->isEmpty()) {
        $result[$name] = null;
        continue;
      }
      $def = $field->getFieldDefinition();
      $type = $def->getType();
      $target = $def->getSetting('target_type');
      // \Drupal::logger('pika_json')->debug('Paragraph field: @f (@t)->@tg', ['@f' => $name, '@t' => $type, '@tg' => $target]);
      
      // Nested paragraphs
      if ($type === 'entity_reference_revisions' && $target === 'paragraph') {
        $nested = array_map([$this, 'parseParagraph'], $field->referencedEntities());
        $result[$name] = count($nested) === 1 ? $nested[0] : $nested;
      }
      // Related object: node reference
      elseif (($type === 'entity_reference' || $type === 'typed_relation') && $target === 'node') {
        $node_ref = $field->entity;
        if ($node_ref instanceof Node) {
          $node_data = ['nid' => $node_ref->id(), 'title' => $node_ref->label()];
          // media of related node
          $media = $this->islandoraUtils->getMedia($node_ref);
          $node_data['media'] = array_map([$this, 'parseMedia'], $media);
          $result[$name] = $node_data;
        } else {
          $result[$name] = null;
        }
      }
      // Taxonomy
      elseif (($type === 'entity_reference' || $type === 'typed_relation') && $target === 'taxonomy_term') {
        $result[$name] = $this->parseTaxonomyField($field, $type === 'typed_relation');
      }
      // Simple
      elseif (in_array($type, ['string', 'string_long', 'text', 'text_long', 'integer'])) {
        $values = array_column($field->getValue(), 'value');
        $result[$name] = count($values) === 1 ? $values[0] : $values;
      }
      // Default
      else {
        $val = $field->getValue();
        if (is_array($val) && count($val) === 1 && is_array($val[0])) {
          $flat = $val[0];
          $result[$name] = count($flat) === 1 ? reset($flat) : $flat;
        } else {
          $result[$name] = $val;
        }
      }
    }
    return $result;
  }

  /**
   * Parses a taxonomy term reference field into a structured array.
   *
   * If the field contains a single term, returns an associative array with
   * its ID, name, and vocabulary. If multiple terms are present, returns an
   * array of such arrays. Returns null if no terms are referenced.
   *
   * For typed_relation fields (Controlled Access Terms module), also extracts
   * the relation type (e.g., 'relators:aut', 'relators:ctb').
   *
   * @param mixed $field
   *   The field referencing one or more taxonomy terms.
   * @param bool $is_typed_relation
   *   Whether this is a typed_relation field that includes relation types.
   *
   * @return array|string|null
   *   A single term array, an array of term arrays, or null if empty.
   */
  private function parseTaxonomyField($field, bool $is_typed_relation = false): array|string|null
  {
    $terms = [];

    if ($is_typed_relation) {
      // Get the relation types from field settings
      $field_def = $field->getFieldDefinition();
      $rel_types = $field_def->getSetting('rel_types') ?? [];

      // For typed_relation fields, iterate over field items to access relation types
      foreach ($field as $item) {
        $term = $item->entity;
        if (!$term) {
          continue;
        }

        $data = [
          'tid' => $term->id(),
          'name' => $term->label(),
          'vocabulary' => $term->bundle(),
        ];

        // Extract relation type information
        if (isset($item->rel_type) && $item->rel_type) {
          $data['relation'] = $item->rel_type;

          // Get the human-readable label from field settings
          if (isset($rel_types[$item->rel_type])) {
            $data['relation_label'] = $rel_types[$item->rel_type];
          }
        } else {
          $data['relation'] = null;
        }

        if ($term->hasField('field_namespace')) {
          if(!$term->get('field_namespace')->isEmpty()){
            $data['namespace'] = $term->get('field_namespace')->value;
          } else {
            $data['namespace'] = null;
          }
        }

        if ($term->hasField('field_thumbnail') && !$term->get('field_thumbnail')->isEmpty()) {
          $data['field_thumbnail'] = $this->parseThumbnailField($term->get('field_thumbnail'));
        }
        $terms[] = $data;
      }
    } else {
      // For regular entity_reference fields, use referencedEntities()
      foreach ($field->referencedEntities() as $term) {

        $data = ['tid' => $term->id(), 'name' => $term->label(), 'vocabulary' => $term->bundle()];

        if ($term->hasField('field_namespace')) {
          if(!$term->get('field_namespace')->isEmpty()){
            $data['namespace'] = $term->get('field_namespace')->value;
          } else {
            $data['namespace'] = null;
          }
        }

        if ($term->hasField('field_thumbnail') && !$term->get('field_thumbnail')->isEmpty()) {
          $data['field_thumbnail'] = $this->parseThumbnailField($term->get('field_thumbnail'));
        }
        $terms[] = $data;
      }
    }
    return count($terms) === 1 ? $terms[0] : $terms;
  }

  /**
   * Parses a media entity into a structured array.
   *
   * Extracts basic metadata (ID, bundle, title) and field values from the media
   * entity. Handles taxonomy term references, file/image fields (returning URL,
   * MIME type, and filename), simple scalar fields, and flattens complex values
   * where appropriate.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity to parse.
   *
   * @return array
   *   An associative array representing the media entity and its fields.
   */
  private function parseMedia(MediaInterface $media): array
  {
    $islandora_media = ['mid' => $media->id(), 'bundle' => $media->bundle(), 'title' => $media->label()];
    foreach ($media->getFields() as $media_field_name => $media_field) {
      if (in_array($media_field_name, $this->fieldsToSkip)) {
        continue;
      }
      if ($media_field->isEmpty()) {
        $islandora_media[$media_field_name] = null;
        continue;
      }
      $media_def = $media_field->getFieldDefinition();
      $media_field_type = $media_def->getType();
      $tg = $media_def->getSetting('target_type');
      if (($media_field_type === 'entity_reference' || $media_field_type === 'typed_relation') && $tg === 'taxonomy_term') {
        $islandora_media[$media_field_name] = $this->parseTaxonomyField($media_field, $media_field_type === 'typed_relation');
      } elseif ($media_field_type === 'image' || $media_field_type === 'file') {
        $file = $media_field->entity;
        if ($file) {
          $islandora_media[$media_field_name] = ['url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()), 'mime' => $file->getMimeType(), 'filename' => $file->getFilename()];
        }
      } elseif (in_array($media_field_type, ['string', 'string_long', 'text', 'text_long', 'integer'])) {
        $values = array_column($media_field->getValue(), 'value');
        $islandora_media[$media_field_name] = count($values) === 1 ? $values[0] : $values;
      } else {
        $val = $media_field->getValue();
        if (is_array($val) && count($val) === 1 && is_array($val[0])) {
          $fl = $val[0];
          $islandora_media[$media_field_name] = count($fl) === 1 ? reset($fl) : $fl;
        } else {
          $islandora_media[$media_field_name] = $val;
        }
      }
    }
    return $islandora_media;
  }

  /**
   * Gets child nodes that reference the given node via field_member_of.
   *
   * This method queries for all nodes where the field_member_of field
   * references the provided node. Should only be called for Compound Objects.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The parent node whose children we want to find.
   *
   * @return \Drupal\node\NodeInterface[]
   *   An array of child node entities.
   */
  private function getChildNodes(Node $node): array
  {
    $entity_type_manager = \Drupal::entityTypeManager();

    // Query for nodes that reference this node via field_member_of
    $query = $entity_type_manager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition(IslandoraUtils::MEMBER_OF_FIELD, $node->id());

    $nids = $query->execute();

    if (empty($nids)) {
      return [];
    }

    return $entity_type_manager->getStorage('node')->loadMultiple($nids);
  }

  /**
   * Parses a child node into a structured array for JSON output.
   *
   * Extracts basic metadata from a child node, including ID, title,
   * bundle (content type), and optionally the first media item if available.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The child node to parse.
   *
   * @return array
   *   An associative array with node metadata.
   */
  private function parseChildNode($node): array
  {
    $child_data = [
      'nid' => $node->id(),
      'title' => $node->label(),
      'bundle' => $node->bundle(),
    ];

    $node->getFields();
    
    // Add media
    $media_items = $this->islandoraUtils->getMedia($node);
    if (!empty($media_items)) {
      foreach($media_items as $media_item) {
        $media = $this->parseMedia($media_item);
        $media_id = $media['mid'];
        $child_data['media'][$media_id] = $media;
      }
    } else {
      $child_data['media'] = null;
    }

    return $child_data;
  }

  /**
   * Returns a JSON representation of a taxonomy term and its fields.
   *
   * Serializes all relevant fields of the given taxonomy term, including
   * nested taxonomy references, image/file fields, and simple scalar values.
   *
   * Refer to pika_json.routing.yml.
   *
   * @param \Drupal\taxonomy\Entity\Term $taxonomy_term
   *   The taxonomy term entity to be serialized to JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing structured data for the term and its fields.
   */
  public function termJson(Term $taxonomy_term): JsonResponse
  {
    $data = [
      'tid'        => $taxonomy_term->id(),
      'name'       => $taxonomy_term->label(),
      'vocabulary' => $taxonomy_term->bundle(),
    ];

    foreach ($taxonomy_term->getFields() as $field_name => $field) {
      if (in_array($field_name, $this->fieldsToSkip)) {
        continue;
      }
      if ($field->isEmpty()) {
        $data[$field_name] = null;
        continue;
      }

      $def    = $field->getFieldDefinition();
      $type   = $def->getType();
      $target = $def->getSetting('target_type');

      // Nested taxonomy reference (e.g. parent term, related terms)
      if (($type === 'entity_reference' || $type === 'typed_relation') && $target === 'taxonomy_term') {
        $data[$field_name] = $this->parseTaxonomyField($field, $type === 'typed_relation');
      }
      // Paragraphs
      elseif ($type === 'entity_reference_revisions' && $target === 'paragraph') {
        $items = array_map([$this, 'parseParagraph'], $field->referencedEntities());
        $data[$field_name] = count($items) === 1 ? $items[0] : $items;
      }
      // Image / file field (e.g. field_thumbnail stored as file)
      elseif ($type === 'image' || $type === 'file') {
        $file = $field->entity;
        if ($file) {
          $data[$field_name] = [
            'url'      => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
            'mime'     => $file->getMimeType(),
            'filename' => $file->getFilename(),
          ];
        } else {
          $data[$field_name] = null;
        }
      }
      // Media reference (e.g. field_thumbnail referencing a media entity)
      elseif ($type === 'entity_reference' && $target === 'media') {
        $media = $field->entity;
        if ($media instanceof MediaInterface) {
          $data[$field_name] = $this->parseMedia($media);
        } else {
          $data[$field_name] = null;
        }
      }
      // Simple scalar fields
      elseif (in_array($type, ['string', 'string_long', 'text', 'text_long', 'integer', 'boolean'])) {
        $values = array_column($field->getValue(), 'value');
        $data[$field_name] = count($values) === 1 ? $values[0] : $values;
      }
      // Default: flatten single-key arrays, pass through everything else
      else {
        $value = $field->getValue();
        if (is_array($value) && count($value) === 1 && is_array($value[0])) {
          $flat = $value[0];
          $data[$field_name] = count($flat) === 1 ? reset($flat) : $flat;
        } else {
          $data[$field_name] = $value;
        }
      }
    }

    return new JsonResponse($data);
  }

  /**
   * Parses a field_thumbnail field into a structured array.
   *
   * Handles three cases: media entity reference, image field, and file field.
   * For image fields, includes alt, title, width, and height in addition to
   * the URL, MIME type, and filename.
   *
   * @param mixed $field
   *   The field_thumbnail field item list.
   *
   * @return array|null
   *   Structured thumbnail data, or null if the file cannot be resolved.
   */
  private function parseThumbnailField($field): array|null
  {
    $def = $field->getFieldDefinition();
    $type = $def->getType();
    $target = $def->getSetting('target_type');

    if ($target === 'media') {
      $media = $field->entity;
      if ($media instanceof MediaInterface) {
        return $this->parseMedia($media);
      }
      return null;
    }

    $item = $field->first();
    $file = $item->entity;
    if (!$file) {
      return null;
    }

    $result = [
      'url'      => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
      'mime'     => $file->getMimeType(),
      'filename' => $file->getFilename(),
    ];

    if ($type === 'image') {
      $result['alt']    = $item->alt ?? null;
      $result['title']  = $item->title ?? null;
      $result['width']  = $item->width ?? null;
      $result['height'] = $item->height ?? null;
    }

    return $result;
  }

}
// end PikaJsonController.php
