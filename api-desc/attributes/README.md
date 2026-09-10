# Attribute Module — API Documentation

## Overview

The Attribute module manages product attributes (e.g., Size, Color, Material) and their values. Attributes define product variation dimensions. The module provides full CRUD via a single controller.

## Key Files

| File | Role |
|------|------|
| `packages/marvel/src/Http/Controllers/AttributeController.php` | CRUD controller |
| `packages/marvel/src/Http/Requests/AttributeRequest.php` | Create/Update validation |
| `packages/marvel/src/Http/Resources/AttributeResource.php` | API resource |
| `packages/marvel/src/Database/Models/Attribute.php` | Model (HasTranslations, Sluggable) |
| `packages/marvel/src/Database/Repositories/AttributeRepository.php` | Repository |

## Permissions

| Permission | API Action |
|------------|-----------|
| `view-attributes` | GET /attributes, GET /attributes/{id} |
| `create-attribute` | POST /attributes |
| `update-attribute` | PUT /attributes/{id} |
| `delete-attribute` | DELETE /attributes/{id} |

## Routes (CRUD only)

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| GET | `/api/v1/attributes` | Controller middleware | `view-attributes` | List attributes (paginated, sortable) |
| POST | `/api/v1/attributes` | Controller middleware | `create-attribute` | Create attribute with optional values |
| GET | `/api/v1/attributes/{id}` | Controller middleware | `view-attributes` | Show attribute by ID or slug with values |
| PUT | `/api/v1/attributes/{id}` | Controller middleware | `update-attribute` | Update attribute name and/or sync values |
| DELETE | `/api/v1/attributes/{id}` | Controller middleware | `delete-attribute` | Delete attribute (cascades to values) |
