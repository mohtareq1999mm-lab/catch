<?php

namespace Marvel\Enums;

use BenSampo\Enum\Enum;

/**
 * Typed identifiers for the fixed set of static pages.
 *
 * The seeder is the single source of truth for the initial records; pages
 * cannot be created or deleted through the API.
 */
final class StaticPageIdentifier extends Enum
{
    public const ABOUT_US = 'about-us';
    public const TERMS_AND_CONDITIONS = 'terms-and-conditions';
    public const PRIVACY_POLICY = 'privacy-policy';
}