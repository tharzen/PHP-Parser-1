#!/usr/bin/env php
<?php

require_once "C:/Users/AlecBlagg/reversible-php/bam.php";
use bam;

foreach ([__DIR__ . '/../../../autoload.php', __DIR__ . '/../vendor/autoload.php'] as $file) {
    if (file_exists($file)) {
        require $file;
        break;
    }
}

ini_set('xdebug.max_nesting_level', 3000);

// Disable XDebug var_dump() output truncation
ini_set('xdebug.var_display_max_children', -1);
ini_set('xdebug.var_display_max_data', -1);
ini_set('xdebug.var_display_max_depth', -1);

list($operations, $files, $attributes) = parseArgs($argv);

/* Dump nodes by default */
if (empty($operations)) {
    $operations[] = 'dump';
}

if (empty($files)) {
    showHelp("Must specify at least one file.");
}

$lexer = new PhpParser\Lexer\Emulative(['usedAttributes' => [
    'startLine', 'endLine', 'startFilePos', 'endFilePos', 'comments'
]]);
$parser = (new PhpParser\ParserFactory)->create(
    PhpParser\ParserFactory::ONLY_PHP5,
    $lexer
);
/*$dumper = new PhpParser\NodeDumper([
    'dumpComments' => true,
    'dumpPositions' => $attributes['with-positions'],
]);
$prettyPrinter = new PhpParser\PrettyPrinter\Standard;

$traverser = new PhpParser\NodeTraverser();
$traverser->addVisitor(new PhpParser\NodeVisitor\NameResolver);
*/

foreach ($files as $file) {
    if (strpos($file, '<?php') === 0) {
        $code = $file;
        fwrite(STDERR, "====> Code $code\n");
    } else {
        if (!file_exists($file)) {
            fwrite(STDERR, "File $file does not exist.\n");
            exit(1);
        }

        $code = file_get_contents($file);
        fwrite(STDERR, "====> File $file:\n");
    }

    if ($attributes['with-recovery']) {
        $errorHandler = new PhpParser\ErrorHandler\Collecting;
        $stmts = $parser->parse($code, $errorHandler);
        foreach ($errorHandler->getErrors() as $error) {
            $message = formatErrorMessage($error, $code, $attributes['with-column-info']);
            fwrite(STDERR, $message . "\n");
        }
        if (null === $stmts) {
            continue;
        }
    } else {
        try {
            $stmts = $parser->parse($code);
            $bamStmts = makeBam($stmts);
            $res = bam\apply($bamStmts, $code);
            print_r($res);
            //echo "result\n\n";
            /*print_r($stmts);
            foreach ($stmts as $bamThing) {
                $bamThing = recApply($bamThing, $code);
            }*/
        } catch (PhpParser\Error $error) {
            $message = formatErrorMessage($error, $code, $attributes['with-column-info']);
            fwrite(STDERR, $message . "\n");
            exit(1);
        }
    }

    /*foreach ($operations as $operation) {
        if ('dump' === $operation) {
            fwrite(STDERR, "==> Node dump:\n");
            echo $dumper->dump($stmts, $code), "\n";
        } elseif ('pretty-print' === $operation) {
            fwrite(STDERR, "==> Pretty print:\n");
            echo $prettyPrinter->prettyPrintFile($stmts), "\n";
        } elseif ('json-dump' === $operation) {
            fwrite(STDERR, "==> JSON dump:\n");
            echo json_encode($stmts, JSON_PRETTY_PRINT), "\n";
        } elseif ('var-dump' === $operation) {
            fwrite(STDERR, "==> var_dump():\n");
            var_dump($stmts);
        } elseif ('resolve-names' === $operation) {
            fwrite(STDERR, "==> Resolved names.\n");
            $stmts = $traverser->traverse($stmts);
        }
    }*/
}


/*function recApply($bamInstr, $code) {
    if (gettype($bamInstr) != "object") {
        return $bamInstr;
    }
    foreach ($bamInstr as $) {
        echo "new apply\n";
        print_r($bamInstrs);
        print_r($value);
        $newVal = bam\apply($value, $code);
        $newVal = recApply($newVal, $code);
        $bamInstr->$key = $newVal;
    }
    return $bamInstr;
}*/

function makeBam($stmts) {
    $bamArr = [];
    foreach ($stmts as $stmt) {
        array_push($bamArr, bamSwitch($stmt));
    }
    return bam\Create($bamArr);
}

function handleArr($exprs) {
    //echo "\n\nhandleExprs\n\n";
    $exprArr = [];
    //print_r($exprs);
    foreach ($exprs as $expr) {
        //echo print_r($expr);
        array_push($exprArr, bamSwitch($expr));
    }
    return $exprArr;
}

function bamSwitch($obj) { //should i go through arrays and bam items, some things with names need to be checked
    if ($obj == null) {
        return null;
    }
    $type = $obj->getType();
    switch ($type) {
        case "Stmt_Expression":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "expr" => bamSwitch($obj->expr),
                "attributes" => bam\Create($obj->attributes)
            ]);
            /*$new = bam\Create(\PhpParser\Node\Stmt\Expression::class);
            //$new = \PhpParser\Node\Stmt\Expression;
            $obj->expr; //will be its own thing to check
            $obj->attributes; //check for comments*/
            break;
        case "Expr_Assign":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "var" => bamSwitch($obj->var),
                "expr" => bamSwitch($obj->expr),
                "attributes" => bam\Create($obj->attributes)
            ]);
            /*$new = bam\Create(\PhpParser\Node\Expr\Assign::class);
            //$new = \PhpParser\Node\Expr\Assign;
            $obj->var; // use start and end pos
            $obj->expr; // will be its own thing to check
            $obj->attributes; //check for comments*/
            break;
        case "Expr_Variable":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "name" => is_string($obj->name) ?
                    bam\ReuseArray($obj->attributes["startFilePos"] + 1, bam\Create(),
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"], bam\Reuse()) :
                    bamSwitch($obj->name),
                "attributes" => bam\Create($obj->attributes)
            ]);
            /*$new = bam\Create(\PhpParser\Node\Expr\Variable::class);
            //$new = \PhpParser\Node\Expr\Variable;
            $obj->name; // either string or expr
            $obj->attributes; //check for comments*/
            break;
        case "Scalar_LNumber":
        case "Scalar_DNumber":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "value" => bam\ReuseArray($obj->attributes["startFilePos"], bam\Create(),
                    $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] + 1, bam\Reuse()),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Scalar_String":
        case "Scalar_EncapsedStringPart":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "value" => bam\ReuseArray($obj->attributes["startFilePos"] + 1, bam\Create(),
                    $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1, bam\Reuse()),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Stmt_Echo":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "exprs" => bam\Create(handleArr($obj->exprs)),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_Array": // might need to do bamswitch stuff on items
            $new = bam\Create([
                "objName" => bam\Create($type),
                "items" => bam\Create($obj->items),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_ArrayDimFetch":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "var" => bamSwitch($obj->var),
                "dim" => bamSwitch($obj->dim),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_ArrayItem":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "key" => bamSwitch($obj->key),
                "value" => bamSwitch($obj->value),
                "byRef" => bam\Create($obj->byRef),
                "unpack" => bam\Create($obj->unpack),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_ArrowFunction": //has this->returnType and returnType???
            $new = bam\Create([
                "objName" => bam\Create($type),
                "static" => bam\Create($obj->static),
                "byRef" => bam\Create($obj->byRef),
                "params" => bam\Create($obj->params),
                "returnType" => bamSwitch($obj->returnType),
                "expr" => bamSwitch($obj->expr),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_AssignRef":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "var" => bamSwitch($obj->var),
                "expr" => bamSwitch($obj->expr),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_BitwiseNot":
        case "Expr_BooleanNot":
        case "Expr_Clone":
        case "Expr_Empty":
        case "Expr_ErrorSuppress":
        case "Expr_Eval":
        case "Expr_Exit":
        case "Expr_Print":
        case "Expr_UnaryMinus":
        case "Expr_UnaryPlus":
        case "Expr_YieldFrom":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "expr" => bamSwitch($obj->expr),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_ClassConstFetch":
        case "Expr_StaticPropertyFetch":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "class" => bamSwitch($obj->class),
                "name" => is_string($obj->name) ?
                    bam\ReuseArray($obj->attributes["startFilePos"] + 1, bam\Create(),
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1, bam\Reuse()) :
                    bamSwitch($obj->name),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_Closure":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "static" => bam\Create($obj->expr),
                "byRef" => bam\Create($obj->byRef),
                "params" => bam\Create($obj->params),
                "uses" => bam\Create($obj->uses),
                "returnType" => bamSwitch($obj->returnType),
                "stmts" => bam\Create(handleArr($obj->stmts)),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_ClosureUse":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "var" => bamSwitch($obj->var),
                "byRef" => bam\Create($obj->byRef),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_ConstFetch":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "name" => bamSwitch($obj->name),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_Error":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_FuncCall":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "name" => bamSwitch($obj->name),
                "args" => bam\Create(handleArr($obj->args)),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_Include":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "expr" => bamSwitch($obj->expr),
                "type" => bam\Create($obj->type),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_Instanceof":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "expr" => bamSwitch($obj->expr),
                "class" => bamSwitch($obj->class),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_Isset":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "vars" => bam\Create(handleArr($obj->vars)),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_List": //figure out what items are
            $new = bam\Create([
                "objName" => bam\Create($type),
                "items" => bam\Create(handleArr($obj->items)),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_MethodCall":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "var" => bamSwitch($obj->var),
                "name" => is_string($obj->name) ?
                    bam\ReuseArray($obj->attributes["startFilePos"] + 1, bam\Create(),
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1, bam\Reuse()) :
                    bamSwitch($obj->name),
                "args" => bam\Create(handleArr($obj->args)),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_New":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "class" => bamSwitch($obj->class),
                "args" => bam\Create(handleArr($obj->args)),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_PostDec":
        case "Expr_PostInc":
        case "Expr_PreDec":
        case "Expr_PreInc":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "var" => bamSwitch($obj->var),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_PropertyFetch":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "var" => bamSwitch($obj->var),
                "name" => is_string($obj->name) ?
                    bam\ReuseArray($obj->attributes["startFilePos"] + 1, bam\Create(),
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1, bam\Reuse()) :
                    bamSwitch($obj->name),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_ShellExec":
        case "Scalar_Encapsed":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "parts" => bam\Create(handleArr($obj->parts)),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_StaticCall":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "class" => bamSwitch($obj->class),
                "name" => is_string($obj->name) ?
                    bam\ReuseArray($obj->attributes["startFilePos"] + 1, bam\Create(),
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1, bam\Reuse()) :
                    bamSwitch($obj->name),
                "args" => bam\Create(handleArr($obj->args)),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_Ternary":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "cond" => bamSwitch($obj->cond),
                "if" => bamSwitch($obj->if),
                "else" => bamSwitch($obj->else),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Expr_Yield":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "key" => bamSwitch($obj->key),
                "value" => bamSwitch($obj->value),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Stmt_Break":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "num" => bamSwitch($obj->num),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Stmt_Case":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "cond" => bamSwitch($obj->cond),
                "stmts" => bam\Create(handleArr($obj->stmts)),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Stmt_Catch":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "types" => bam\Create(handleArr($obj->types)),
                "var" => bamSwitch($obj->var),
                "stmts" => bam\Create(handleArr($obj->stmts)),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Stmt_Class":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "flag" => bam\Create($obj->flag),
                "name" => is_string($obj->name) ?
                    bam\ReuseArray($obj->attributes["startFilePos"] + 1, bam\Create(),
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1, bam\Reuse()) :
                    bamSwitch($obj->name),
                "implements" => bam\Create(handleArr($obj->implements)),
                "extends" => bamSwitch($obj->extends),
                "stmts" => bam\Create(handleArr($obj->stmts)),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Stmt_ClassConst":
            $new = bam\Create([
                "objName" => bam\Create($type),
                "flags" => bam\Create($obj->flags),
                "consts" => bam\Create(handleArr($obj->consts)),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;
        case "Stmt_ClassMethod": //params?? handleArr??
            $new = bam\Create([
                "objName" => bam\Create($type),
                "flags" => bam\Create($obj->flags),
                "byRef" => bam\Create($obj->byRef),
                "name" => is_string($obj->name) ?
                    bam\ReuseArray($obj->attributes["startFilePos"] + 1, bam\Create(),
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1, bam\Reuse()) :
                    bamSwitch($obj->name),
                "params" => bam\Create($obj->params),
                "returnType" => bamSwitch($obj->returnType),
                "stmts" => bam\Create(handleArr($obj->stmts)),
                "attributes" => bam\Create($obj->attributes)
            ]);
            break;

        default:
            if (substr($type, 0, 13) == "Expr_AssignOp") {
                $new = bam\Create([
                    "objName" => bam\Create($type),
                    "var" => bamSwitch($obj->var),
                    "expr" => bamSwitch($obj->expr),
                    "attributes" => bam\Create($obj->attributes)
                ]);
            } else if (substr($type, 0, 13) == "Expr_BinaryOp") {
                $new = bam\Create([
                    "objName" => bam\Create($type),
                    "left" => bamSwitch($obj->left),
                    "right" => bamSwitch($obj->right),
                    "attributes" => bam\Create($obj->attributes)
                ]);
            } else if (substr($type, 0, 13) == "Expr_Cast") {
                $new = bam\Create([
                    "objName" => bam\Create($type),
                    "expr" => bamSwitch($obj->expr),
                    "attributes" => bam\Create($obj->attributes)
                ]);
            } else if (substr($type, 0, 4) == "Name") { //unsure on parts
                $new = bam\Create([
                    "objName" => bam\Create($type),
                    "parts" => bam\Create($obj->parts),
                    "attributes" => bam\Create($obj->attributes)
                ]);
            } else if (substr($type, 0, 17) == "Scalar_MagicConst") {
                $new = bam\Create([
                    "objName" => bam\Create($type),
                    "attributes" => bam\Create($obj->attributes)
                ]);
            }
            else{
                $new = bam\Create($obj);
            }
    }
    return $new;
}


function formatErrorMessage(PhpParser\Error $e, $code, $withColumnInfo) {
    if ($withColumnInfo && $e->hasColumnInfo()) {
        return $e->getMessageWithColumnInfo($code);
    } else {
        return $e->getMessage();
    }
}

function showHelp($error = '') {
    if ($error) {
        fwrite(STDERR, $error . "\n\n");
    }
    fwrite($error ? STDERR : STDOUT, <<<OUTPUT
Usage: php-parse [operations] file1.php [file2.php ...]
   or: php-parse [operations] "<?php code"
Turn PHP source code into an abstract syntax tree.

Operations is a list of the following options (--dump by default):

    -d, --dump              Dump nodes using NodeDumper
    -p, --pretty-print      Pretty print file using PrettyPrinter\Standard
    -j, --json-dump         Print json_encode() result
        --var-dump          var_dump() nodes (for exact structure)
    -N, --resolve-names     Resolve names using NodeVisitor\NameResolver
    -c, --with-column-info  Show column-numbers for errors (if available)
    -P, --with-positions    Show positions in node dumps
    -r, --with-recovery     Use parsing with error recovery
    -h, --help              Display this page

Example:
    php-parse -d -p -N -d file.php

    Dumps nodes, pretty prints them, then resolves names and dumps them again.


OUTPUT
    );
    exit($error ? 1 : 0);
}

function parseArgs($args) {
    $operations = [];
    $files = [];
    $attributes = [
        'with-column-info' => false,
        'with-positions' => false,
        'with-recovery' => false,
    ];

    array_shift($args);
    $parseOptions = true;
    foreach ($args as $arg) {
        if (!$parseOptions) {
            $files[] = $arg;
            continue;
        }

        switch ($arg) {
            case '--dump':
            case '-d':
                $operations[] = 'dump';
                break;
            case '--pretty-print':
            case '-p':
                $operations[] = 'pretty-print';
                break;
            case '--json-dump':
            case '-j':
                $operations[] = 'json-dump';
                break;
            case '--var-dump':
                $operations[] = 'var-dump';
                break;
            case '--resolve-names':
            case '-N';
                $operations[] = 'resolve-names';
                break;
            case '--with-column-info':
            case '-c';
                $attributes['with-column-info'] = true;
                break;
            case '--with-positions':
            case '-P':
                $attributes['with-positions'] = true;
                break;
            case '--with-recovery':
            case '-r':
                $attributes['with-recovery'] = true;
                break;
            case '--help':
            case '-h';
                showHelp();
                break;
            case '--':
                $parseOptions = false;
                break;
            default:
                if ($arg[0] === '-') {
                    showHelp("Invalid operation $arg.");
                } else {
                    $files[] = $arg;
                }
        }
    }

    return [$operations, $files, $attributes];
}
