<?php

declare(strict_types=1);

namespace Psl\URI\Internal;

use Psl\URI\Exception\InvalidTemplateException;

use function explode;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * RFC 6570 URI Template parser.
 *
 * Parses a template string into a list of parts: literal strings and expression descriptors.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc6570
 *
 * @internal
 */
final class TemplateParser
{
    private const string OPERATORS = '+#./;?&';

    /**
     * Parse a URI template into its constituent parts.
     *
     * @return list<string|array{operator: string, variables: list<array{name: string, modifier: string, prefix: null|int}>}>
     *
     * @throws InvalidTemplateException If the template syntax is invalid.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc6570#section-2
     */
    public static function parse(string $template): array
    {
        $parts = [];
        $length = strlen($template);
        $i = 0;

        while ($i < $length) {
            $openBrace = strpos($template, '{', $i);
            if ($openBrace === false) {
                $literal = substr($template, $i);
                if ($literal !== '') {
                    $parts[] = $literal;
                }

                break;
            }

            if ($openBrace > $i) {
                /** @var non-negative-int $literalLen */
                $literalLen = $openBrace - $i;
                $parts[] = substr($template, $i, $literalLen);
            }

            $closeBrace = strpos($template, '}', $openBrace);
            if ($closeBrace === false) {
                throw InvalidTemplateException::forUnclosedExpression($openBrace);
            }

            /** @var non-negative-int $exprLen */
            $exprLen = $closeBrace - $openBrace - 1;
            $expression = substr($template, $openBrace + 1, $exprLen);
            $parts[] = self::parseExpression($expression);

            $i = $closeBrace + 1;
        }

        return $parts;
    }

    /**
     * Parse a single template expression.
     *
     * @return array{operator: string, variables: list<array{name: string, modifier: string, prefix: null|int}>}
     *
     * @throws InvalidTemplateException If the expression syntax is invalid.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc6570#section-2.2
     */
    private static function parseExpression(string $expression): array
    {
        $operator = '';
        $variableList = $expression;

        if ($expression !== '' && str_contains(self::OPERATORS, $expression[0])) {
            $operator = $expression[0];
            $variableList = substr($expression, 1);
        } elseif ($expression !== '' && !preg_match('/^[a-zA-Z0-9_%.]/', $expression)) {
            throw InvalidTemplateException::forInvalidOperator($expression[0]);
        }

        $variables = [];
        $varSpecs = explode(',', $variableList);

        foreach ($varSpecs as $varSpec) {
            $varSpec = trim($varSpec, " \t\r\n");
            if ($varSpec === '') {
                throw InvalidTemplateException::forEmptyVariableName();
            }

            $modifier = '';
            $prefix = null;

            if (str_ends_with($varSpec, '*')) {
                $modifier = '*';
                /** @var non-negative-int $starLen */
                $starLen = strlen($varSpec) - 1;
                $varSpec = substr($varSpec, 0, $starLen);
            } elseif (str_contains($varSpec, ':')) {
                $colonPos = strpos($varSpec, ':');
                /** @var non-negative-int $colonPos */
                $prefixStr = substr($varSpec, $colonPos + 1);
                $varSpec = substr($varSpec, 0, $colonPos);

                if (!preg_match('/^[0-9]+$/', $prefixStr) || (int) $prefixStr > 10_000) {
                    throw InvalidTemplateException::forInvalidModifier(':' . $prefixStr);
                }

                $modifier = ':';
                $prefix = (int) $prefixStr;
            }

            if ($varSpec === '') {
                throw InvalidTemplateException::forEmptyVariableName();
            }

            $variables[] = ['name' => $varSpec, 'modifier' => $modifier, 'prefix' => $prefix];
        }

        return ['operator' => $operator, 'variables' => $variables];
    }
}
