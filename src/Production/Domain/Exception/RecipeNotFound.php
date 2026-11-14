<?php

declare(strict_types=1);

namespace Production\Domain\Exception;

final class RecipeNotFound extends \DomainException
{
    public static function withId(string $recipeId): self
    {
        return new self("Recipe not found: {$recipeId}");
    }

    public static function noActiveVersion(string $recipeId): self
    {
        return new self("No active version found for recipe: {$recipeId}");
    }
}
