<?php

declare(strict_types=1);

namespace Geni\Inference\Transformers;

use Geni\Inference\Document\Schema;
use Geni\Inference\FullRuleSetTransformer;

/**
 * Handles the Laravel `confirmed` validation rule.
 *
 * Implies a sibling field `{field}_confirmation` with identical type constraints.
 *
 * Framework-free.
 */
final class ConfirmedRuleTransformer implements FullRuleSetTransformer
{
    public function transform(array $properties, array $required, array $rawRules, string $file, int $line): array
    {
        foreach ($rawRules as $fieldName => $rulesList) {
            $hasConfirmed = false;
            foreach ($rulesList as $rule) {
                if (strtolower($rule) === 'confirmed') {
                    $hasConfirmed = true;
                    break;
                }
            }

            if ($hasConfirmed) {
                $confirmFieldName = $fieldName.'_confirmation';

                if (! isset($properties[$confirmFieldName]) && isset($properties[$fieldName])) {
                    $origSchema = $properties[$fieldName];
                    $confirmSchema = new Schema;
                    $confirmSchema->type = $origSchema->type;
                    $confirmSchema->format = $origSchema->format;
                    $confirmSchema->minLength = $origSchema->minLength;
                    $confirmSchema->maxLength = $origSchema->maxLength;

                    $properties[$confirmFieldName] = $confirmSchema;

                    if (in_array($fieldName, $required, true) && ! in_array($confirmFieldName, $required, true)) {
                        $required[] = $confirmFieldName;
                    }
                }
            }
        }

        return [
            'properties' => $properties,
            'required' => $required,
            'diagnostics' => [],
        ];
    }
}
