# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`pika_json` is a Drupal custom module (Drupal 10/11) for the Marmot Library Network. It exposes three JSON API endpoints for Islandora repository objects:

- `GET /pika-json/node/{nid}` — serializes a node and all its Islandora media
- `GET /pika-json/node/{nid}/children` — paginated list of child nodes (nodes whose `field_member_of` references `{nid}`); each child is a full node representation identical to the node endpoint
- `GET /pika-json/taxonomy/{tid}` — serializes a taxonomy term

All endpoints require the `access content` permission and return `application/json`.

### Children endpoint parameters

| Parameter | Default | Description                |
| --------- | ------- | -------------------------- |
| `number`  | `10`    | Items per page (minimum 1) |
| `page`    | `1`     | 1-indexed page number      |

Response shape:

```json
{
  "parent_nid": 42,
  "page": 1,
  "count": 10,
  "total": 47,
  "children": [ ... ]
}
```

## Development context

This module runs inside a full Drupal + Islandora site. There is no standalone build or test runner in this repo — development and testing happen by installing the module in a Drupal site.

**Enable/disable the module:**

```bash
drush en pika_json
drush pmu pika_json
```

**After code changes, clear Drupal's cache:**

```bash
drush cr
```

**Tail Drupal logs (useful when debugging — uncomment the `\Drupal::logger` calls in the controller):**

```bash
drush watchdog:show --tail
```

## Release and deployment workflow

This repo is installed into the Drupal site via Composer as a VCS package. The workflow is:

**1. Develop and release here:**

```bash
# Make changes, commit, push
git commit -am "Your change"
git push origin main

# Tag a new release
git tag 1.0.1 && git push origin 1.0.1
```

**2. Pull the new version into the Drupal site:**

```bash
# Update composer.json version constraint if needed, then:
docker-compose exec -T drupal with-contenv bash -lc "COMPOSER_MEMORY_LIMIT=-1 composer update marmot/pika_json"
```

## Architecture

All logic lives in `src/Controller/PikaJsonController.php`. The controller is injected with three services via `ContainerInterface`:

| Service                | Property            | Purpose                                  |
| ---------------------- | ------------------- | ---------------------------------------- |
| `entity_field.manager` | `$fieldManager`     | Retrieve field definitions               |
| `islandora.utils`      | `$islandoraUtils`   | Fetch Islandora media attached to nodes  |
| `file_url_generator`   | `$fileUrlGenerator` | Generate absolute URLs for file entities |

### Field type dispatch

Both `nodeJson()` and `termJson()` iterate over entity fields and dispatch on field type. The pattern is the same throughout the codebase:

- **`entity_reference` / `typed_relation` → `taxonomy_term`**: calls `parseTaxonomyField()`, which extracts `tid`, `name`, `vocabulary`, optional `field_namespace`, optional `field_thumbnail`, and (for typed_relation) `relation` + `relation_label` from Controlled Access Terms.
- **`entity_reference_revisions` → `paragraph`**: calls `parseParagraph()` recursively, which itself handles nested paragraphs, node references (with their media), and taxonomy references.
- **`entity_reference` → `media`**: calls `parseMedia()`.
- **`image` / `file`**: resolves the file entity and returns `url`, `mime`, `filename`.
- **Simple scalars** (`string`, `string_long`, `text`, `text_long`, `integer`, `boolean`): extracted via `array_column($field->getValue(), 'value')`.
- **Default**: flattens single-element arrays where possible.

Single-item arrays are unwrapped to a scalar/object throughout — only multi-value fields produce arrays in the output.

### Islandora-specific behaviour

- After processing node fields, `nodeJson()` calls `$this->islandoraUtils->getMedia($node)` to attach media.
- For nodes whose `field_model` term label is `Compound Object` or `Collection`, child nodes (those with `field_member_of` pointing to the parent) are fetched via an entity query and attached as `children`.

### Fields excluded from output

The `$fieldsToSkip` list suppresses noisy Drupal internals (`revision_log`, `uid`, `promote`, `sticky`, `default_langcode`, `uuid`, etc.) from all serialized entities.
