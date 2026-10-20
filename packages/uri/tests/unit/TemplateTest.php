<?php

declare(strict_types=1);

namespace Psl\URI\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\URI;
use Psl\URI\Exception\InvalidTemplateException;

final class TemplateTest extends TestCase
{
    /**
     * @return array<string, null|string|list<string>|array<string, string>>
     */
    private static function variables(): array
    {
        return [
            'var' => 'value',
            'hello' => 'Hello World!',
            'path' => '/foo/bar',
            'x' => '1024',
            'y' => '768',
            'empty' => '',
            'list' => ['red', 'green', 'blue'],
            'keys' => ['semi' => ';', 'dot' => '.', 'comma' => ','],
            'empty_list' => [],
            'empty_keys' => [],
            'null_var' => null,
            'spaced' => 'hello world',
            'path_var' => '/foo/bar/',
            'unicode' => 'Привет',
        ];
    }

    #[DataProvider('expansionProvider')]
    public function testExpansion(string $template, string $expected): void
    {
        $t = URI\Template\parse($template);
        static::assertSame($template, $t->toString());

        $uri = $t->expand(self::variables());

        static::assertSame($expected, $uri->toString());
    }

    public static function expansionProvider(): array
    {
        return [
            ['{var}',             'value'],
            ['{hello}',           'Hello%20World%21'],
            ['{x,y}',             '1024,768'],
            ['{+var}',            'value'],
            ['{+hello}',          'Hello%20World!'],
            ['{+path}',           '/foo/bar'],
            ['{#var}',            '#value'],
            ['{#hello}',          '#Hello%20World!'],
            ['{.var}',            '.value'],
            ['{.x,y}',            '.1024.768'],
            ['{/var}',            '/value'],
            ['{/var,x}',          '/value/1024'],
            ['{;x,y}',            ';x=1024;y=768'],
            ['{;x,y,empty}',      ';x=1024;y=768;empty'],
            ['{?x,y}',            '?x=1024&y=768'],
            ['{?x,y,empty}',      '?x=1024&y=768&empty='],
            ['{&x,y}',            '&x=1024&y=768'],
            ['{var:3}',           'val'],
            ['{list}',            'red,green,blue'],
            ['{list*}',           'red,green,blue'],
            ['{/list*}',          '/red/green/blue'],
            ['{?list*}',          '?list=red&list=green&list=blue'],
            ['{keys}',            'semi,%3B,dot,.,comma,%2C'],
            ['{keys*}',           'semi=%3B,dot=.,comma=%2C'],
            ['{?keys*}',          '?semi=%3B&dot=.&comma=%2C'],
            ['{/null_var}',       ''],
            ['{/empty}',          '/'],
            ['{.null_var}',       ''],
            ['{.empty}',          '.'],
            ['{;null_var}',       ''],
            ['{;empty}',          ';empty'],
            ['{?null_var}',       ''],
            ['{?empty}',          '?empty='],
            ['{?null_var,empty}', '?empty='],
            ['{?empty,null_var}', '?empty='],
            ['{/empty_list}',     ''],
            ['{/empty_list*}',    ''],
            ['{?empty_keys*}',    ''],
            ['{.list}',           '.red,green,blue'],
            ['{.list*}',          '.red.green.blue'],
            ['{/list}',           '/red,green,blue'],
            ['{/list*}',          '/red/green/blue'],
            ['{.keys}',           '.semi,%3B,dot,.,comma,%2C'],
            ['{.keys*}',          '.semi=%3B.dot=..comma=%2C'],
            ['{/keys}',           '/semi,%3B,dot,.,comma,%2C'],
            ['{/keys*}',          '/semi=%3B/dot=./comma=%2C'],
            ['{;keys}',           ';keys=semi,%3B,dot,.,comma,%2C'],
            ['{;keys*}',          ';semi=%3B;dot=.;comma=%2C'],
            ['{path_var}',        '%2Ffoo%2Fbar%2F'],
            ['{+path_var}',       '/foo/bar/'],
            ['{?path_var}',       '?path_var=%2Ffoo%2Fbar%2F'],
            ['{spaced}',          'hello%20world'],
            ['{+spaced}',         'hello%20world'],
            ['{var:30}',          'value'],
            ['{unicode:3}',       '%D0%9F%D1%80%D0%B8'],
        ];
    }

    public function testToStringReturnsOriginalTemplate(): void
    {
        $template = URI\Template\parse('http://example.com/{var}');

        static::assertSame('http://example.com/{var}', $template->toString());
    }

    public function testStringableReturnsOriginalTemplate(): void
    {
        $template = URI\Template\parse('{var}');

        static::assertSame('{var}', (string) $template);
    }

    public function testUndefinedVariablesAreOmitted(): void
    {
        $template = URI\Template\parse('{undefined}');
        $uri = $template->expand([]);

        static::assertSame('', $uri->toString());
    }

    public function testInvalidTemplateThrowsException(): void
    {
        $this->expectException(InvalidTemplateException::class);

        URI\Template\parse('{unclosed');
    }

    public function testNumericIntegerValue(): void
    {
        $template = URI\Template\parse('{num}');
        $uri = $template->expand(['num' => 42]);

        static::assertSame('42', $uri->toString());
    }

    public function testNumericFloatValue(): void
    {
        $template = URI\Template\parse('{num}');
        $uri = $template->expand(['num' => 3.14]);

        static::assertSame('3.14', $uri->toString());
    }

    public function testMultipleUndefinedVariables(): void
    {
        $template = URI\Template\parse('{a,b,c}');
        $uri = $template->expand([]);

        static::assertSame('', $uri->toString());
    }

    public function testWhitespaceAroundVariableSpecsIsIgnored(): void
    {
        $template = URI\Template\parse("{x, \ty\r\n}");
        $uri = $template->expand(['x' => '1', 'y' => '2']);

        static::assertSame('1,2', $uri->toString());
    }

    /**
     * Control characters are not stripped from a varspec, so "var\f" does not resolve to "var".
     *
     * RFC 6570 does not allow them in a varname at all; rejecting them belongs to variable name
     * validation, which the parser does not perform yet.
     */
    #[DataProvider('nonWhitespaceControlCharacterProvider')]
    public function testControlCharactersAroundVariableSpecsAreNotIgnored(string $char): void
    {
        $template = URI\Template\parse("{x,{$char}var{$char}}");
        $uri = $template->expand(['var' => 'value']);

        static::assertSame('', $uri->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonWhitespaceControlCharacterProvider(): iterable
    {
        yield 'form feed' => ["\x0C"];
        yield 'vertical tab' => ["\x0B"];
        yield 'NUL byte' => ["\0"];
    }

    public function testPartiallyUndefinedVariables(): void
    {
        $template = URI\Template\parse('{a,b,c}');
        $uri = $template->expand(['b' => 'found']);

        static::assertSame('found', $uri->toString());
    }

    public function testEmptyStringTemplate(): void
    {
        $template = URI\Template\parse('');

        static::assertSame('', $template->toString());
        $uri = $template->expand([]);
        static::assertSame('', $uri->toString());
    }

    public function testTemplateWithOnlyLiterals(): void
    {
        $template = URI\Template\parse('http://example.com/path');
        $uri = $template->expand([]);

        static::assertSame('http://example.com/path', $uri->toString());
    }

    public function testAdjacentExpressions(): void
    {
        $template = URI\Template\parse('{x}{y}');
        $uri = $template->expand(['x' => 'a', 'y' => 'b']);

        static::assertSame('ab', $uri->toString());
    }

    public function testInvalidOperatorRejection(): void
    {
        $this->expectException(InvalidTemplateException::class);

        URI\Template\parse('{!var}');
    }

    public function testEmptyVariableNameRejection(): void
    {
        $this->expectException(InvalidTemplateException::class);

        URI\Template\parse('{,}');
    }

    public function testEmptyExpressionRejection(): void
    {
        $this->expectException(InvalidTemplateException::class);

        URI\Template\parse('{}');
    }

    public function testAdjacentExpressionsWithOperators(): void
    {
        $template = URI\Template\parse('{/x}{.y}');
        $uri = $template->expand(['x' => 'a', 'y' => 'b']);

        static::assertSame('/a.b', $uri->toString());
    }

    public function testLiteralBetweenExpressions(): void
    {
        $template = URI\Template\parse('{x}-{y}');
        $uri = $template->expand(['x' => 'hello', 'y' => 'world']);

        static::assertSame('hello-world', $uri->toString());
    }

    public function testNullVariableIsOmitted(): void
    {
        $template = URI\Template\parse('{?a,b}');
        $uri = $template->expand(['a' => null, 'b' => 'val']);

        static::assertSame('?b=val', $uri->toString());
    }

    public function testIntegerZeroValue(): void
    {
        $template = URI\Template\parse('{num}');
        $uri = $template->expand(['num' => 0]);

        static::assertSame('0', $uri->toString());
    }

    public function testMultipleUnclosedExpression(): void
    {
        $this->expectException(InvalidTemplateException::class);

        URI\Template\parse('hello{world');
    }
}
