<?php
/**
 * Quack Compiler and toolkit
 * Copyright (C) 2016 Marcelo Camargo <marcelocamargo@linuxmail.org> and
 * CONTRIBUTORS.
 *
 * This file is part of Quack.
 *
 * Quack is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Quack is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Quack.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace QuackCompiler\Parselets;

use \QuackCompiler\Parser\Grammar;
use \QuackCompiler\Ast\Expr\Expr;
use \QuackCompiler\Ast\Expr\LambdaExpr;
use \QuackCompiler\Lexer\Tag;
use \QuackCompiler\Lexer\Token;

class FunctionParselet implements IPrefixParselet
{
    const TYPE_EXPRESSION = 0x1;
    const TYPE_STATEMENT  = 0x2;

    private $is_static;

    public function __construct($is_static = false)
    {
        $this->is_static = $is_static;
    }

    public function parse(Grammar $grammar, Token $token)
    {
        $this->is_static && $grammar->parser->consume(); // Eat [fn] when static function
        // TODO: implement use
        ($by_reference = $grammar->parser->is('*')) && $grammar->parser->consume();

        $parameters = [];
        $type = null;
        $value = null;
        $lexical_vars = [];

        $grammar->parser->match('{');

        while ($grammar->checker->startsParameter()) {
            $parameters[] = $grammar->_parameter();

            if ($grammar->parser->is(';')) {
                $grammar->parser->consume();
            } else {
                break;
            }
        }

        $grammar->parser->match('|');
        if ($grammar->parser->is('[')) {
            $type = static::TYPE_STATEMENT;
            $grammar->parser->match('[');
            $value = iterator_to_array($grammar->_innerStmtList());
            $grammar->parser->match(']');
        } else {
            $type = static::TYPE_EXPRESSION;
            $value = $grammar->_expr();
        }

        $grammar->parser->match('}');

        if ($grammar->parser->is(Tag::T_DERIVING)) {
            $grammar->parser->consume();

            if ($grammar->parser->is('{')) {
                do {
                    $grammar->parser->consume();
                    ($deriving_by_reference = $grammar->parser->is('*')) && $grammar->parser->consume();
                    $lexical_vars[] = ($deriving_by_reference ? '*' : '') . $grammar->identifier();
                } while ($grammar->parser->is(';'));

                $grammar->parser->match('}');
            } else {
                ($deriving_by_reference = $grammar->parser->is('*')) && $grammar->parser->consume();
                $lexical_vars[] = ($deriving_by_reference ? '*' : '') . $grammar->identifier();
            }
        }

        return new LambdaExpr($by_reference, $parameters, $type, $value, $this->is_static, $lexical_vars);
    }
}
