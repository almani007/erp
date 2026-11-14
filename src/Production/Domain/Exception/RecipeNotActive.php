<?php

declare(strict_types=1);

namespace Production\Domain\Exception;

final class RecipeNotActive extends \DomainException
{
    public static function forRecipe(string $recipeId): self
    {
        return new self("Recipe [{$recipeId}] is not active or has no effective version.");
    }
}
