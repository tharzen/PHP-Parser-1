PHP Parser
==========

[![Build Status](https://travis-ci.org/nikic/PHP-Parser.svg?branch=master)](https://travis-ci.org/nikic/PHP-Parser) [![Coverage Status](https://coveralls.io/repos/github/nikic/PHP-Parser/badge.svg?branch=master)](https://coveralls.io/github/nikic/PHP-Parser?branch=master)

This is a PHP 5.2 to PHP 7.4 parser written in PHP. Its purpose is to simplify static code analysis and
manipulation.

[**Documentation for version 4.x**][doc_master] (stable; for running on PHP >= 7.0; for parsing PHP 5.2 to PHP 7.4).

[Documentation for version 3.x][doc_3_x] (unsupported; for running on PHP >= 5.5; for parsing PHP 5.2 to PHP 7.2).

Features
--------

The main features provided by this library are:

 * Parsing PHP 5 and PHP 7 code into an abstract syntax tree (AST).
   * Invalid code can be parsed into a partial AST.
   * The AST contains accurate location information.
 * Dumping the AST in human-readable form.
 * Converting an AST back to PHP code.
   * Experimental: Formatting can be preserved for partially changed ASTs.
 * Infrastructure to traverse and modify ASTs.
 * Resolution of namespaced names.
 * Evaluation of constant expressions.
 * Builders to simplify AST construction for code generation.
 * Converting an AST into JSON and back.

Tharzen Quick Start
-------------------

First get this repository onto your local system. Then install [composer](https://getcomposer.org). Composer can be installed either in the locally just in the repository, or globally on your system, although this does effect the sytax of the commands. To install dependencies run either:

````php composer.phar install````(if installed locally)
or
````composer install```` (if installed globally)

You will also need to have installed the bam.php file in the tharzen/reversible-php repository on your system. In the future ideally this can be automated with a package manager. But as of now you should manually clone the reversible-php repository somewhere on your system, or simply get the bam.php file. Then identify instances where ````use bam```` are in the php parser system, and replace the ````require_once```` line above with a path to the bam.php on your system.

Potential temporary stopgap script may be added to address this before more permanent solution.

To run test, from top directory of php-parser repository run:

````./bin/php-parse -d -P -
j --var-dump ./lib/PhpParser/test.php
````

which should generate output:

````====> File ./lib/PhpParser/test.php:
==> Node dump:
array(
    0: Stmt_Expression[2:1 - 2:13](
        expr: Expr_Assign[2:1 - 2:12](
            var: Expr_Variable[2:1 - 2:2](
                name: b
            )
            expr: Scalar_String[2:6 - 2:12](
                value: hello
            )
        )
    )
    1: Stmt_Expression[3:1 - 3:8](
        expr: Expr_Assign[3:1 - 3:7](
            var: Expr_Variable[3:1 - 3:2](
                name: a
            )
            expr: Scalar_LNumber[3:6 - 3:7](
                value: 33
            )
        )
    )
)
````

To view the code being tested, look at the test.php file located at ./lib/PhpParser/test.php.

Quick Start
-----------

Install the library using [composer](https://getcomposer.org) (this installs the original repository):

    php composer.phar require nikic/php-parser

Parse some PHP code into an AST and dump the result in human-readable form:

```php
<?php
use PhpParser\Error;
use PhpParser\NodeDumper;
use PhpParser\ParserFactory;

$code = <<<'CODE'
<?php

function test($foo)
{
    var_dump($foo);
}
CODE;

$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
try {
    $ast = $parser->parse($code);
} catch (Error $error) {
    echo "Parse error: {$error->getMessage()}\n";
    return;
}

$dumper = new NodeDumper;
echo $dumper->dump($ast) . "\n";
```

This dumps an AST looking something like this:

```
array(
    0: Stmt_Function(
        byRef: false
        name: Identifier(
            name: test
        )
        params: array(
            0: Param(
                type: null
                byRef: false
                variadic: false
                var: Expr_Variable(
                    name: foo
                )
                default: null
            )
        )
        returnType: null
        stmts: array(
            0: Stmt_Expression(
                expr: Expr_FuncCall(
                    name: Name(
                        parts: array(
                            0: var_dump
                        )
                    )
                    args: array(
                        0: Arg(
                            value: Expr_Variable(
                                name: foo
                            )
                            byRef: false
                            unpack: false
                        )
                    )
                )
            )
        )
    )
)
```

Let's traverse the AST and perform some kind of modification. For example, drop all function bodies:

```php
use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

$traverser = new NodeTraverser();
$traverser->addVisitor(new class extends NodeVisitorAbstract {
    public function enterNode(Node $node) {
        if ($node instanceof Function_) {
            // Clean out the function body
            $node->stmts = [];
        }
    }
});

$ast = $traverser->traverse($ast);
echo $dumper->dump($ast) . "\n";
```

This gives us an AST where the `Function_::$stmts` are empty:

```
array(
    0: Stmt_Function(
        byRef: false
        name: Identifier(
            name: test
        )
        params: array(
            0: Param(
                type: null
                byRef: false
                variadic: false
                var: Expr_Variable(
                    name: foo
                )
                default: null
            )
        )
        returnType: null
        stmts: array(
        )
    )
)
```

Finally, we can convert the new AST back to PHP code:

```php
use PhpParser\PrettyPrinter;

$prettyPrinter = new PrettyPrinter\Standard;
echo $prettyPrinter->prettyPrintFile($ast);
```

This gives us our original code, minus the `var_dump()` call inside the function:

```php
<?php

function test($foo)
{
}
```

Simpler Example:
Code:
````
<?php
$b = "hello";
$a = 33;
?>
````

Output AST:
````
array(
    0: Stmt_Expression[2:1 - 2:13](
        expr: Expr_Assign[2:1 - 2:12](
            var: Expr_Variable[2:1 - 2:2](
                name: b
            )
            expr: Scalar_String[2:6 - 2:12](
                value: hello
            )
        )
    )
    1: Stmt_Expression[3:1 - 3:8](
        expr: Expr_Assign[3:1 - 3:7](
            var: Expr_Variable[3:1 - 3:2](
                name: a
            )
            expr: Scalar_LNumber[3:6 - 3:7](
                value: 33
            )
        )
    )
)
````

For a more comprehensive introduction, see the documentation.

Documentation
-------------

 1. [Introduction](doc/0_Introduction.markdown)
 2. [Usage of basic components](doc/2_Usage_of_basic_components.markdown)

Component documentation:

 * [Walking the AST](doc/component/Walking_the_AST.markdown)
   * Node visitors
   * Modifying the AST from a visitor
   * Short-circuiting traversals
   * Interleaved visitors
   * Simple node finding API
   * Parent and sibling references
 * [Name resolution](doc/component/Name_resolution.markdown)
   * Name resolver options
   * Name resolution context
 * [Pretty printing](doc/component/Pretty_printing.markdown)
   * Converting AST back to PHP code
   * Customizing formatting
   * Formatting-preserving code transformations
 * [AST builders](doc/component/AST_builders.markdown)
   * Fluent builders for AST nodes
 * [Lexer](doc/component/Lexer.markdown)
   * Lexer options
   * Token and file positions for nodes
   * Custom attributes
 * [Error handling](doc/component/Error_handling.markdown)
   * Column information for errors
   * Error recovery (parsing of syntactically incorrect code)
 * [Constant expression evaluation](doc/component/Constant_expression_evaluation.markdown)
   * Evaluating constant/property/etc initializers
   * Handling errors and unsupported expressions
 * [JSON representation](doc/component/JSON_representation.markdown)
   * JSON encoding and decoding of ASTs
 * [Performance](doc/component/Performance.markdown)
   * Disabling XDebug
   * Reusing objects
   * Garbage collection impact
 * [Frequently asked questions](doc/component/FAQ.markdown)
   * Parent and sibling references

 [doc_3_x]: https://github.com/nikic/PHP-Parser/tree/3.x/doc
 [doc_master]: https://github.com/nikic/PHP-Parser/tree/master/doc
