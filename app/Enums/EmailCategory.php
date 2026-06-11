<?php

namespace App\Enums;

enum EmailCategory: string
{
    case Support = 'support';
    case Billing = 'billing';
    case Sales = 'sales';
    case FeatureRequest = 'feature_request';
    case Bug = 'bug';
    case Spam = 'spam';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Support => 'Support',
            self::Billing => 'Facturatie',
            self::Sales => 'Sales',
            self::FeatureRequest => 'Feature request',
            self::Bug => 'Bug',
            self::Spam => 'Spam',
            self::Other => 'Overig',
        };
    }

    /**
     * Flux badge color for this category.
     */
    public function color(): string
    {
        return match ($this) {
            self::Support => 'blue',
            self::Billing => 'amber',
            self::Sales => 'green',
            self::FeatureRequest => 'purple',
            self::Bug => 'red',
            self::Spam => 'zinc',
            self::Other => 'zinc',
        };
    }

    /**
     * Resolve a (possibly AI-provided) value to a known category, defaulting to Other.
     */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Other;
    }

    /**
     * Comma-separated machine values for use in an AI prompt.
     */
    public static function promptList(): string
    {
        return implode(', ', array_map(fn (self $category): string => $category->value, self::cases()));
    }
}
