#!/usr/bin/env php
<?php

require_once __DIR__ . "/../../bam.php";
use bam\{function Reuse, function Create, function Up, function Down, function Offset, function Concat, function Insert,
    function Remove, function Keep, function Fork, function apply, function andThen, function ReuseArray, function Custom, function stringOf};

foreach ([__DIR__ . '/../../../autoload.php', __DIR__ . '/../vendor/autoload.php'] as $file) {
    if (file_exists($file)) {
        require $file;
        break;
    }
}

// try backPropagate on parser

// signature of parse function:
// file name, file contents
// takes array two elements: file contents, file name
// return edit action

// handle comments


ini_set('xdebug.max_nesting_level', 3000);

// Disable XDebug var_dump() output truncation
ini_set('xdebug.var_display_max_children', -1);
ini_set('xdebug.var_display_max_data', -1);
ini_set('xdebug.var_display_max_depth', -1);

//list($operations, $files, $attributes) = parseArgs($argv);

/* Dump nodes by default */
/*if (empty($operations)) {
    $operations[] = 'dump';
}

if (empty($files)) {
    showHelp("Must specify at least one file.");
}*/
/*$dumper = new PhpParser\NodeDumper([
    'dumpComments' => true,
    'dumpPositions' => $attributes['with-positions'],
]);
$prettyPrinter = new PhpParser\PrettyPrinter\Standard;

$traverser = new PhpParser\NodeTraverser();
$traverser->addVisitor(new PhpParser\NodeVisitor\NameResolver);
*/


// parse function:
// $code: the code to be parsed
// $filename: the filename that is being parsed if it is a file: used for __FILE__
// $pathToFilename: not currently used
// This uses the lexer and parser to break up given $code
// the makeBam() function then turns this parsed ast into bam statements
function parse($code, $filename, $pathToFilename) {
    $lexer = new PhpParser\Lexer\Emulative(['usedAttributes' => [
        'startLine', 'endLine', 'startFilePos', 'endFilePos', 'comments'
    ]]);
    $parser = (new PhpParser\ParserFactory)->create(
        PhpParser\ParserFactory::ONLY_PHP5,
        $lexer
     );
    //foreach ($files as $file) {

    // do i work based on reading from file or reading code
    /*if (strpos($code, '<?php') === 0) {
        fwrite(STDERR, "====> Code $code\n");
    }*/ /*else {
        if (!file_exists($file)) {
            fwrite(STDERR, "File $file does not exist.\n");
            exit(1);
        }

        $code = file_get_contents($file);
        fwrite(STDERR, "====> File $file:\n");
    }*/
    try {
        $stmts = $parser->parse($code);
        //print_r($stmts);
        //echo stringOf($bamStmts);//print_r($bamStmts);
        return makeBam($stmts, $filename);
        //print_r($bamStmts);
        //return $bamStmts;
        //echo "result\n\n";
        /*print_r($stmts);
        foreach ($stmts as $bamThing) {
            $bamThing = recApply($bamThing, $code);
        }*/
    } catch (PhpParser\Error $error) {
        $message = formatErrorMessage($error, $code, false/*$attributes['with-column-info']*/);
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}


/*function recApply($bamInstr, $code) {
    if (gettype($bamInstr) != "object") {
        return $bamInstr;
    }
    foreach ($bamInstr as $) {
        echo "new apply\n";
        print_r($bamInstrs);
        print_r($value);
        $newVal = apply($value, $code);
        $newVal = recApply($newVal, $code);
        $bamInstr->$key = $newVal;
    }
    return $bamInstr;
}*/

// makeBam() function goes through the generated AST to create bam instructions
// $stmts: the array that needs to be converted
// $fileName: the fileName, used for __FILE__
function makeBam($stmts, $filename) {
    $bamArr = [];
    foreach ($stmts as $stmt) {
        array_push($bamArr, bamSwitch($stmt, $filename));
    }
    return Create($bamArr);
}

// handleArr() function for handling conversion of array items into bam expressions
// loops through items to convert individual elements into bam instructions
// $exprs: the array to be converted
// $filename: the filename to be passed for __FILE__
function handleArr($exprs, $filename) {
    //echo "\n\nhandleExprs\n\n";
    if(!is_array($exprs)) {
      return Create($exprs);
    }
    $exprArr = [];
    //print_r($exprs);
    foreach ($exprs as $expr) {
        //echo print_r($expr);
        array_push($exprArr, bamSwitch($expr, $filename));
    }
    return $exprArr;
}

// bamSwitch() main function for conversion of AST to bam instructions
// checks the type of each element of AST and has bam instructions for them
// recursively goes through elements that have children to be turned to bam instructions
// $obj: the current object being converted to a bam instruction
// $fileName: used for __FILE__
function bamSwitch($obj, $filename) { //should i go through arrays and bam items, some things with names need to be checked
    if ($obj == null) {
        return null;
    }
    if (is_string($obj)) {
        return Create($obj);
        //what to do here
    }
    $type = $obj->getType();
    switch ($type) {
        case "Stmt_Expression":
        case "Stmt_Return":
        case "Stmt_Throw":
            $new = Create([
                "expr" => bamSwitch($obj->expr, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
            /*$new = Create(\PhpParser\Node\Stmt\Expression::class);
            //$new = \PhpParser\Node\Stmt\Expression;
            $obj->expr; //will be its own thing to check
            $obj->attributes; //check for comments*/
            break;
        case "Expr_Assign":
            $new = Create([
                "var" => bamSwitch($obj->var, $filename),
                "expr" => bamSwitch($obj->expr, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
            /*$new = Create(\PhpParser\Node\Expr\Assign::class);
            //$new = \PhpParser\Node\Expr\Assign;
            $obj->var; // use start and end pos
            $obj->expr; // will be its own thing to check
            $obj->attributes; //check for comments*/
            break;
        case "Expr_Variable":
        case "Stmt_Goto":
        case "Stmt_Label":
            $new = Create([
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"])) :
                    bamSwitch($obj->name, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
            /*$new = Create(\PhpParser\Node\Expr\Variable::class);
            //$new = \PhpParser\Node\Expr\Variable;
            $obj->name; // either string or expr
            $obj->attributes; //check for comments*/
            break;
        case "Scalar_LNumber":
        case "Scalar_DNumber":
            // custom function to properly convert input into integer for reading and string for backPropagation
            $new = Create([
                "value" => Custom(Down(Offset($obj->attributes["startFilePos"],
                    $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] + 1)),
                function ($x) {return intval($x);},
                function ($edit, $oldInput, $oldOutput) {
                    $newNum = $edit->model;
                    // TODO to handle the strings starting 0x and with 0
                    return Create(strval($newNum));
                }),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Scalar_String":
        case "Scalar_EncapsedStringPart":
        case "Stmt_InlineHTML":
            // custom function to properly parse /n
            $new = Create([
                "value" => Custom(Down(Offset($obj->attributes["startFilePos"] + 1,
                    $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)),
                    function($x) {return str_replace("\\n", "\n", $x);},
                    function($editAction, $x, $oldResult) {
                      return $editAction; //str_replace("\n", "\\n", $x);
                    },
                    "String process"),
                    // TODO create edit action that does transformation for all special characters
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
            // can also delete the start, keep length
            // Delete($obj->attributes["startFilePos"] + 1, Keep($obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1), Down(Offset(0, 0))
        case "Stmt_Echo":
            $new = Create([
                "exprs" => Create(handleArr($obj->exprs, $filename)),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Array": // might need to do bamswitch stuff on items
            $new = Create([
                "items" => Create(handleArr($obj->items, $filename)),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_ArrayDimFetch":
            $new = Create([
                "var" => bamSwitch($obj->var, $filename),
                "dim" => bamSwitch($obj->dim, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_ArrayItem":
            $new = Create([
                "key" => bamSwitch($obj->key, $filename),
                "value" => bamSwitch($obj->value, $filename),
                "byRef" => Create($obj->byRef),
                "unpack" => Create($obj->unpack),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_ArrowFunction": //has this->returnType and returnType???
            $new = Create([
                "static" => Create($obj->static),
                "byRef" => Create($obj->byRef),
                "params" => Create($obj->params),
                "returnType" => bamSwitch($obj->returnType, $filename),
                "expr" => bamSwitch($obj->expr, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_AssignRef":
            $new = Create([
                "var" => bamSwitch($obj->var, $filename),
                "expr" => bamSwitch($obj->expr, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
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
            $new = Create([
                "expr" => bamSwitch($obj->expr, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_ClassConstFetch":
        case "Expr_StaticPropertyFetch":
            $new = Create([
                "class" => bamSwitch($obj->class, $filename),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Closure":
            $new = Create([
                "static" => Create($obj->static),
                "byRef" => Create($obj->byRef),
                "params" => Create($obj->params),
                "uses" => Create($obj->uses),
                "returnType" => bamSwitch($obj->returnType, $filename),
                "stmts" => Create(handleArr($obj->stmts, $filename)),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_ClosureUse":
            $new = Create([
                "var" => bamSwitch($obj->var, $filename),
                "byRef" => Create($obj->byRef),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_ConstFetch":
            $new = Create([
                "name" => bamSwitch($obj->name, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Error":
            $new = Create([
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_FuncCall":
            $new = Create([
                "name" => bamSwitch($obj->name, $filename),
                "args" => Create(handleArr($obj->args)),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Include":
            $new = Create([
                "expr" => bamSwitch($obj->expr, $filename),
                "type" => Create($obj->type),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Instanceof":
            $new = Create([
                "expr" => bamSwitch($obj->expr, $filename),
                "class" => bamSwitch($obj->class, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Isset":
        case "Stmt_Global":
        case "Stmt_Static":
        case "Stmt_Unset":
            $new = Create([
                "vars" => Create(handleArr($obj->vars, $filename)),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_List": //figure out what items are
            $new = Create([
                "items" => Create(handleArr($obj->items, $filename)),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_MethodCall":
            $new = Create([
                "var" => bamSwitch($obj->var, $filename),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name, $filename),
                "args" => Create(handleArr($obj->args, $filename)),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_New":
            $new = Create([
                "class" => bamSwitch($obj->class, $filename),
                "args" => Create(handleArr($obj->args, $filename)),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_PostDec":
        case "Expr_PostInc":
        case "Expr_PreDec":
        case "Expr_PreInc":
            $new = Create([
                "var" => bamSwitch($obj->var, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_PropertyFetch":
            $new = Create([
                "var" => bamSwitch($obj->var, $filename),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_ShellExec":
        case "Scalar_Encapsed":
            $new = Create([
                "parts" => Create(handleArr($obj->parts, $filename)),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_StaticCall":
            $new = Create([
                "class" => bamSwitch($obj->class, $filename),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name, $filename),
                "args" => Create(handleArr($obj->args, $filename)),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Ternary":
            $new = Create([
                "cond" => bamSwitch($obj->cond, $filename),
                "if" => bamSwitch($obj->if, $filename),
                "else" => bamSwitch($obj->else, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Yield":
            $new = Create([
                "key" => bamSwitch($obj->key, $filename),
                "value" => bamSwitch($obj->value, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Break":
        case "Stmt_Continue":
            $new = Create([
                "num" => bamSwitch($obj->num, $filename),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Case":
        case "Stmt_Do":
        case "Stmt_ElseIf":
        case "Stmt_While":
            $new = Create([
                "cond" => bamSwitch($obj->cond, $filename),
                "stmts" => Create(handleArr($obj->stmts, $filename)),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Catch":
            $new = Create([
                "objName" => Create($type),
                "types" => Create(handleArr($obj->types, $filename)),
                "var" => bamSwitch($obj->var, $filename),
                "stmts" => Create(handleArr($obj->stmts, $filename)),
                "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_Class":
            $new = Create([
                "objName" => Create($type),
                "flags" => Create($obj->flags),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name, $filename),
                "implements" => Create(handleArr($obj->implements, $filename)),
                "extends" => bamSwitch($obj->extends, $filename),
                "stmts" => Create(handleArr($obj->stmts, $filename)),
                "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_ClassConst":
            $new = Create([
                "objName" => Create($type),
                "flags" => Create($obj->flags),
                "consts" => Create(handleArr($obj->consts, $filename)),
                "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_ClassMethod": //params?? handleArr??
            $new = Create([
                "objName" => Create($type),
                "flags" => Create($obj->flags),
                "byRef" => Create($obj->byRef),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name, $filename),
                "params" => Create($obj->params),
                "returnType" => bamSwitch($obj->returnType, $filename),
                "stmts" => Create(handleArr($obj->stmts, $filename)),
                "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_Const":
            $new = Create([
                    "objName" => Create($type),
                    "consts" => Create(handleArr($obj->consts, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_Declare":
            $new = Create([
                    "objName" => Create($type),
                    "declares" => Create(handleArr($obj->declares, $filename)),
                    "stmts" => Create(handleArr($obj->stmts, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_DeclareDeclare":
            $new = Create([
                    "objName" => Create($type),
                    "key" => is_string($obj->key) ?
                        Down(Offset($obj->attributes["startFilePos"] + 1,
                            $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        bamSwitch($obj->key, $filename),
                    "value" => bamSwitch($obj->value, $filename),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_Else":
        case "Stmt_Finally":
            $new = Create([
                    "objName" => Create($type),
                    "stmts" => Create(handleArr($obj->stmts, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_For":
            $new = Create([
                    "objName" => Create($type),
                    "init" => Create(handleArr($obj->init, $filename)),
                    "cond" => Create(handleArr($obj->cond, $filename)),
                    "loop" => Create(handleArr($obj->loop, $filename)),
                    "stmts" => Create(handleArr($obj->stmts, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_Foreach":
            $new = Create([
                    "objName" => Create($type),
                    "expr" => bamSwitch($obj->expr, $filename),
                    "keyVar" => bamSwitch($obj->keyVar, $filename),
                    "byRef" => Create($obj->byRef),
                    "valueVar" => bamSwitch($obj->valueVar, $filename),
                    "stmts" => Create(handleArr($obj->stmts, $filename))
            ]);
            break;
        case "Stmt_Function":
            $new = Create([
                    "objName" => Create($type),
                    "byRef" => Create($obj->byRef),
                    "name" => is_string($obj->name) ?
                        Down(Offset($obj->attributes["startFilePos"] + 1,
                            $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        bamSwitch($obj->name, $filename),
                    "params" => Create($obj->params),
                    "returnType" => bamSwitch($obj->returnType, $filename),
                    "stmts" => Create(handleArr($obj->stmts, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_GroupUse":
            $new = Create([
                    "objName" => Create($type),
                    "type" => Create($obj->type),
                    "prefix" => bamSwitch($obj->prefix, $filename),
                    "uses" => Create(handleArr($obj->uses, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_HaltCompiler":
            $new = Create([
                    "objName" => Create($type),
                    "remaining" => Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_If":
            $new = Create([
                    "objName" => Create($type),
                    "cond" => bamSwitch($obj->cond, $filename),
                    "stmts" => Create(handleArr($obj->stmts, $filename)),
                    "elseifs" => Create(handleArr($obj->elseifs, $filename)),
                    "else" => bamSwitch($obj->else, $filename),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_Interface":
            $new = Create([
                    "objName" => Create($type),
                    "name" => is_string($obj->name) ?
                        Down(Offset($obj->attributes["startFilePos"] + 1,
                            $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        bamSwitch($obj->name, $filename),
                    "extends" => Create(handleArr($obj->extends, $filename)),
                    "stmts" => Create(handleArr($obj->stmts, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_Namespace":
            $new = Create([
                    "objName" => Create($type),
                    "name" => bamSwitch($obj->name, $filename),
                    "stmts" => Create(handleArr($obj->stmts, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_Property":
            $new = Create([
                    "objName" => Create($type),
                    "flags" => Create($obj->flags),
                    "props" => Create(handleArr($obj->props, $filename)),
                    "type" => is_string($obj->type) ?
                        Down(Offset($obj->attributes["startFilePos"] + 1,
                            $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        bamSwitch($obj->type, $filename),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_PropertyProperty":
            $new = Create([
                    "objName" => Create($type),
                    "name" => is_string($obj->name) ?
                        Down(Offset($obj->attributes["startFilePos"] + 1,
                            $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        bamSwitch($obj->name, $filename),
                    "default" => bamSwitch($obj->default, $filename),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_StaticVar":
            $new = Create([
                    "objName" => Create($type),
                    "var" => bamSwitch($obj->var, $filename),
                    "default" => bamSwitch($obj->default, $filename),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_Switch":
            $new = Create([
                    "objName" => Create($type),
                    "cond" => bamSwitch($obj->cond, $filename),
                    "cases" => Create(handleArr($obj->cases, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_Trait":
            $new = Create([
                    "objName" => Create($type),
                    "name" => is_string($obj->name) ?
                        Down(Offset($obj->attributes["startFilePos"] + 1,
                            $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        bamSwitch($obj->name, $filename),
                    "stmts" => Create(handleArr($obj->stmts, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_TraitUse":
            $new = Create([
                    "objName" => Create($type),
                    "traits" => Create(handleArr($obj->traits, $filename)),
                    "adaptations" => Create(handleArr($obj->adaptations, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_TryCatch":
            $new = Create([
                    "objName" => Create($type),
                    "stmts" => Create(handleArr($obj->stmts, $filename)),
                    "catches" => Create(handleArr($obj->catches, $filename)),
                    "finally" => bamSwitch($obj->finally, $filename),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_Use":
            $new = Create([
                    "objName" => Create($type),
                    "type" => Create($obj->type),
                    "uses" => Create(handleArr($obj->uses, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_UseUse":
            $new = Create([
                    "objName" => Create($type),
                    "type" => Create($obj->type),
                    "name" => bamSwitch($obj->name, $filename),
                    "alias" => is_string($obj->alias) ?
                        Down(Offset($obj->attributes["startFilePos"] + 1,
                            $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        bamSwitch($obj->alias, $filename),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_TraitUseAdaptation_Alias":
            $new = Create([
                    "objName" => Create($type),
                    "trait" => bamSwitch($obj->trait, $filename),
                    "method" => is_string($obj->method) ?
                        Down(Offset($obj->attributes["startFilePos"] + 1,
                            $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        bamSwitch($obj->method, $filename),
                    "newModifier" => Create($obj->newModifier),
                    "newName" => is_string($obj->newName) ?
                        Down(Offset($obj->attributes["startFilePos"] + 1,
                            $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        bamSwitch($obj->newName, $filename),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Stmt_TraitUseAdaptation_Precedence":
            $new = Create([
                    "objName" => Create($type),
                    "trait" => bamSwitch($obj->trait, $filename),
                    "method" => is_string($obj->method) ?
                        Down(Offset($obj->attributes["startFilePos"] + 1,
                            $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        bamSwitch($obj->method, $filename),
                    "insteadOf" => Create(handleArr($obj->insteadOf, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Arg":
            $new = Create([
                    "objName" => Create($type),
                    "value" => bamSwitch($obj->value, $filename),
                    "byRef" => Create($obj->byRef),
                    "unpack" => Create($obj->unpack),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Const":
            $new = Create([
                    "objName" => Create($type),
                    "name" => is_string($obj->name) ?
                        Down(Offset($obj->attributes["startFilePos"] + 1,
                            $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        bamSwitch($obj->name, $filename),
                    "value" => bamSwitch($obj->value, $filename),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Identifier":
            $new = Create([
                    "objName" => Create($type),
                    "name" => Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "NullableType":
            $new = Create([
                    "objName" => Create($type),
                    "type" => is_string($obj->type) ?
                        Down(Offset($obj->attributes["startFilePos"] + 1,
                            $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        bamSwitch($obj->type, $filename),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Param":
            $new = Create([
                    "objName" => Create($type),
                    "type" => is_string($obj->type) ?
                        Down(Offset($obj->attributes["startFilePos"] + 1,
                            $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        bamSwitch($obj->type, $filename),
                    "byRef" => Create($obj->byRef),
                    "variadic" => Create($obj->variadic),
                    "var" => bamSwitch($obj->var, $filename),
                    "default" => bamSwitch($obj->var, $filename),
                    "flags" => Create(handleArr($obj->flags, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "UnionType":
            $new = Create([
                    "objName" => Create($type),
                    "types" => Create(handleArr($obj->types, $filename)),
                    "attributes" => Create($obj->attributes)
            ]);
            break;
        case "Comment":
            $new = Create([
                    "objName" => Create($type),
                    "text" => Create($obj->text),
                    "startLine" => Create($obj->text),
                    "startFilePos" => Create($obj->text),
                    "startTokenPos" => Create($obj->text),
                    "endLine" => Create($obj->text),
                    "endFilePos" => Create($obj->text),
                    "endTokenPos" => Create($obj->text),
            ]);
            break;
        default:
            if (substr($type, 0, 13) == "Expr_AssignOp") {
                $new = Create([
                    "objName" => Create($type),
                    "var" => bamSwitch($obj->var, $filename),
                    "expr" => bamSwitch($obj->expr, $filename),
                    "attributes" => Create($obj->attributes)
                ]);
            } else if (substr($type, 0, 13) == "Expr_BinaryOp") {
                $new = Create([
                    "objName" => Create($type),
                    "left" => bamSwitch($obj->left, $filename),
                    "right" => bamSwitch($obj->right, $filename),
                    "attributes" => Create($obj->attributes)
                ]);
            } else if (substr($type, 0, 13) == "Expr_Cast") {
                $new = Create([
                    "objName" => Create($type),
                    "expr" => bamSwitch($obj->expr, $filename),
                    "attributes" => Create($obj->attributes)
                ]);
            } else if (substr($type, 0, 4) == "Name") { //unsure on parts
                $new = Create([
                    "objName" => Create($type),
                    "parts" => is_string($obj->parts) ? Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        (is_array($obj->parts) ? Create(handleArr($obj->parts, $filename)) : bamSwitch($obj->parts, $filename)),
                    "attributes" => Create($obj->attributes)
                ]);
            } else if (substr($type, 0, 17) == "Scalar_MagicConst") {
                if ($obj->getName() == "__FILE__") {
                    $new = Create([
                        "objName" => Create($type),
                        "fileName" => Create($filename),
                        "attributes" => Create($obj->attributes)
                    ]);
                } else {
                    $new = Create([
                        "objName" => Create($type),
                        "attributes" => Create($obj->attributes)
                    ]);
                }
            }
            else{
                $new = Create($obj);
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
