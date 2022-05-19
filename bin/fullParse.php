<?php

require_once __DIR__ . "/../../bam.php";
require_once __DIR__ . "/../../clickedit_lenses.php";
use bam\{
    function Create, function Insert, function Reuse,
    function Up,
    function ForgivingDown as Down,
    function RemoveExcept, function Remove, function RemoveAll,
    function Custom, function Lens, function BLens,
    function Replace, function Keep,
    function Concat, function Prepend, function Append,
    function apply,
    function andThen,
    function access,
    function Offset,
    function Interval,
    function stringOf,
    function uneval,
    function setDebug as setBamDebug,
//    function Sequence,
//    function ListElem,
//    function ContextElem,
    function merge,
    function backPropagate,
    function isKeep,
    function isPrepend,
    function isAppend,
    function isReplace,
    function isDown,
    function isConcat,
    function isPureNew,
    function isConst,
    function valueIfConst,
    function isEditAction,
    function SameDownAs,
    function outLength,
    function LessThanUndefined,
    function PlusUndefined,
    function MinUndefined
};
use \bam\transform\{
  function postMap,
  function extractKeep,
  function extractReplace,
  function extractKeepOfReplace};

foreach ([__DIR__ . '/../../../autoload.php', __DIR__ . '/../vendor/autoload.php'] as $file) {
    if (file_exists($file)) {
        require_once $file;
        break;
    }
}

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

function parse($code) {
    $lexer = new PhpParser\Lexer\Emulative(['usedAttributes' => [
        'startLine', 'endLine', 'startFilePos', 'endFilePos', 'comments'
    ]]);
    $parser = (new PhpParser\ParserFactory)->create(
        PhpParser\ParserFactory::ONLY_PHP7,
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
        $bamStmts = makeBam($stmts);
        //echo stringOf($bamStmts);//print_r($bamStmts);
        return $bamStmts;
        //echo "result\n\n";
        /*print_r($stmts);
        foreach ($stmts as $bamThing) {
            $bamThing = recApply($bamThing, $code);
        }*/
    } catch (PhpParser\Error $error) {
        $message = formatErrorMessage($error, $code, NULL /*$attributes['with-column-info']*/);
        echo $message;
        //fwrite(STDERR, $message . "\n");
        //exit(1);
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

function makeBam($stmts) {
    $bamArr = [];
    foreach ($stmts as $stmt) {
        array_push($bamArr, bamSwitch($stmt));
    }
    return Create($bamArr);
}

function handleArr(&$exprs) {
    //echo "\n\nhandleExprs\n\n";
    if(!is_array($exprs)) {
        echo "handleArr on non-array:", uneval($exprs), "\n";
        //debug_print_backtrace();
        return bamSwitch($exprs);
    }
    $exprArr = [];
    //print_r($exprs);
    foreach ($exprs as $k => $expr) {
        //echo print_r($expr);
        bamSwitch($exprs[$k]);
        //array_push($exprArr, bamSwitch($expr));
    }
    return $exprs;
}

function bamSwitch(&$obj) { //should i go through arrays and bam items, some things with names need to be checked
    if ($obj === null) {
        return null;
    }
    if (is_string($obj) || is_integer($obj)) {
        return $obj;
        //what to do here
    }
    if(is_array($obj)) {
      return handleArr($obj);
    }
    if(\bam\isEditAction($obj)) {
      return $obj;
    }
    $type = $obj->getType();
    $new = $obj;
    switch ($type) {
        case "Stmt_Expression":
        case "Stmt_Return":
        case "Stmt_Throw":
            $obj->expr = bamSwitch($obj->expr);
            break;
        case "Expr_Assign":
            $obj->var = bamSwitch($obj->var);
            $obj->expr = bamSwitch($obj->expr);
            break;
        case "Expr_Variable":
        case "Stmt_Goto":
        case "Stmt_Label":
            if(is_string($obj->name)) {
              // Here there is a bug. The end is correct but not always start
              $end = $obj->attributes["endFilePos"] + 1;
              $length = strlen($obj->name);
              $start = $end - $length;
              $obj->name = Down(Offset($start, $length));
            } else {
              $obj->name = bamSwitch($obj->name);
            }
            break;
        case "Scalar_LNumber":
            $numberInterval = Down(Interval($obj->attributes["startFilePos"],
                    $obj->attributes["endFilePos"] + 1));
            $parentInterval = property_exists($obj, "parentAttributes") ? Down(Interval($obj->parentAttributes["startFilePos"], $obj->parentAttributes["endFilePos"] + 1)) : NULL;
            $obj->value = Custom_ScalarLNumber([
              "number" => $numberInterval,
             "parentString" => $parentInterval,
             "parentType" => property_exists($obj, "parent") ? $obj->parent : NULL ]);
            break;
        case "Scalar_DNumber":
            $numberInterval = Down(Interval($obj->attributes["startFilePos"],
                    $obj->attributes["endFilePos"] + 1));
            $parentInterval = property_exists($obj, "parentAttributes") ? Down(Interval($obj->parentAttributes["startFilePos"], $obj->parentAttributes["endFilePos"] + 1)) : NULL;
            $obj->value = Custom_Scalar_DNumber([
              "number" => $numberInterval,
              "parentString" => $parentInterval,
              "parentType" => property_exists($obj, "parent") ? $obj->parent : NULL ]);
            break;
        
        case "Scalar_String":
            // We replace the value by the provided edit action
            $obj->value =
                Down(Interval($obj->attributes["startFilePos"],
                    $obj->attributes["endFilePos"] + 1),
                Custom_Scalar_String(["source" => Reuse()]));
            break;
        case "Scalar_EncapsedStringPart":
            $obj->value = Down(Offset($obj->attributes["startFilePos"],
                    $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] + 1), 
                      Custom_Scalar_EncapsedStringPart(["source" => Reuse()]));
            break;
        case "Scalar_MagicConst_File":
            break;
        case "Stmt_InlineHTML":
            $obj->value = Down(Offset($obj->attributes["startFilePos"],
                    $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] + 1), Custom_Stmt_InlineHTML(["source" => Reuse()]));
            break;
        case "Stmt_Echo":
            $obj->exprs = bamSwitch($obj->exprs);
            break;
        case "Expr_Array": // might need to do bamswitch stuff on items
            $obj->items = bamSwitch($obj->items);
            break;
        case "Expr_ArrayDimFetch":
            $obj->var = bamSwitch($obj->var);
            $obj->dim = bamSwitch($obj->dim);
            break;
        case "Expr_ArrayItem":
            $obj->key = bamSwitch($obj->key);
            $obj->value = bamSwitch($obj->value);
            break;
        case "Expr_ArrowFunction": //has this->returnType and returnType???
            $obj->returnType = bamSwitch($obj->returnType);
            $obj->expr = bamSwitch($obj->expr);
            break;
        case "Expr_AssignRef":
            $obj->var = bamSwitch($obj->var);
            $obj->expr = bamSwitch($obj->expr);
            break;
        case "Expr_BitwiseNot":
        case "Expr_BooleanNot":
        case "Expr_Clone":
        case "Expr_Empty":
        case "Expr_ErrorSuppress":
        case "Expr_Eval":
        case "Expr_Exit":
        case "Expr_Print":
        case "Expr_UnaryPlus":
        case "Expr_UnaryMinus":
        case "Expr_YieldFrom":
            // To deal with UnaryMinus...
            if($obj->expr !== NULL) {
              $obj->expr->parent = $type;
              $obj->expr->parentAttributes = $obj->attributes;
            }
            $obj->expr = bamSwitch($obj->expr);
            break;
        case "Expr_ClassConstFetch":
        case "Expr_StaticPropertyFetch":
            $obj->class = bamSwitch($obj->class);
            $obj->name = is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name);
            break;
        case "Expr_Closure":
            $obj->returnType = bamSwitch($obj->returnType);
            $obj->stmts = bamSwitch($obj->stmts);
            break;
        case "Expr_ClosureUse":
            $obj->var = bamSwitch($obj->var);
            break;
        case "Expr_ConstFetch":
            $obj->name = bamSwitch($obj->name);
            break;
        case "Expr_Error":
            break;
        case "Expr_FuncCall":
            $obj->name = bamSwitch($obj->name);
            $obj->args = bamSwitch($obj->args);
            break;
        case "Expr_Include":
            $obj->expr = bamSwitch($obj->expr);
            break;
        case "Expr_Instanceof":
            $obj->expr = bamSwitch($obj->expr);
            $obj->class = bamSwitch($obj->class);
            break;
        case "Expr_Isset":
        case "Stmt_Global":
        case "Stmt_Static":
        case "Stmt_Unset":
            $obj->vars = bamSwitch($obj->vars);
            break;
        case "Expr_List": //figure out what items are
            $obj->items = bamSwitch($obj->items);
            break;
        case "Expr_MethodCall":
            $obj->var = bamSwitch($obj->var);
            $obj->name = is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name);
            $obj->args = bamSwitch($obj->args);
            break;
        case "Expr_New":
            $obj->class = bamSwitch($obj->class);
            $obj->args = bamSwitch($obj->args);
            break;
        case "Expr_PostDec":
        case "Expr_PostInc":
        case "Expr_PreDec":
        case "Expr_PreInc":
            $obj->var = bamSwitch($obj->var);
            break;
        case "Expr_PropertyFetch":
            $obj->var = bamSwitch($obj->var);
            $obj->name = is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name);
            break;
        case "Expr_ShellExec":
        case "Scalar_Encapsed":
            $obj->parts = bamSwitch($obj->parts);
            break;
        case "Expr_StaticCall":
            $obj->class = bamSwitch($obj->class);
            $obj->name = is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name);
            $obj->args = bamSwitch($obj->args);
            break;
        case "Expr_Ternary":
            $obj->cond = bamSwitch($obj->cond);
            $obj->if = bamSwitch($obj->if);
            $obj->else = bamSwitch($obj->else);
            break;
        case "Expr_Yield":
            $obj->key = bamSwitch($obj->key);
            $obj->value = bamSwitch($obj->value);
            break;
        case "Stmt_Break":
        case "Stmt_Continue":
            $obj->num = bamSwitch($obj->num);
            break;
        case "Stmt_Case":
        case "Stmt_Do":
        case "Stmt_ElseIf":
        case "Stmt_While":
            $obj->cond = bamSwitch($obj->cond);
            $obj->stmts = bamSwitch($obj->stmts);
            break;
        case "Stmt_Catch":
            $obj->types = bamSwitch($obj->types);
            $obj->var = bamSwitch($obj->var);
            $obj->stmts = bamSwitch($obj->stmts);
            break;
        case "Stmt_Class":
            $obj->name = is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name);
            $obj->implements = bamSwitch($obj->implements);
            $obj->extends = bamSwitch($obj->extends);
            $obj->stmts = bamSwitch($obj->stmts);
            break;
        case "Stmt_ClassConst":
            $obj->consts = bamSwitch($obj->consts);
            break;
        case "Stmt_ClassMethod":
            $obj->name = is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name);
            $obj->returnType = bamSwitch($obj->returnType);
            $obj->stmts = bamSwitch($obj->stmts);
            break;
        case "Stmt_Const":
            $obj->consts = bamSwitch($obj->consts);
            break;
        case "Stmt_Declare":
            $obj->declares = bamSwitch($obj->declares);
            $obj->stmts = bamSwitch($obj->stmts);
            break;
        case "Stmt_DeclareDeclare":
            $obj->key = is_string($obj->key) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->key);
            $obj->value = bamSwitch($obj->value);
            break;
        case "Stmt_Else":
        case "Stmt_Finally":
            $obj->stmts = bamSwitch($obj->stmts);
            break;
        case "Stmt_For":
            $obj->init = bamSwitch($obj->init);
            $obj->cond = bamSwitch($obj->cond);
            $obj->loop = bamSwitch($obj->loop);
            $obj->stmts = bamSwitch($obj->stmts);
            break;
        case "Stmt_Foreach":
            $obj->expr = bamSwitch($obj->expr);
            $obj->keyVar = bamSwitch($obj->keyVar);
            $obj->valueVar = bamSwitch($obj->valueVar);
            $obj->stmts = bamSwitch($obj->stmts);
            break;
        case "Stmt_Function":
            $obj->name = bamSwitch($obj->name);
            $obj->params = bamSwitch($obj->params);
            $obj->returnType = bamSwitch($obj->returnType);
            $obj->stmts = bamSwitch($obj->stmts);
            $obj->source = Down(Offset($obj->attributes["startFilePos"], $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] + 1));
            break;
        case "Stmt_GroupUse":
            $obj->prefix = bamSwitch($obj->prefix);
            $obj->uses = bamSwitch($obj->uses);
            break;
        case "Stmt_HaltCompiler":
            $obj->remaining = Down(Offset($obj->attributes["startFilePos"] + 1,
                    $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1));
            break;
        case "Stmt_If":
            $obj->cond = bamSwitch($obj->cond);
            $obj->stmts = bamSwitch($obj->stmts);
            $obj->elseifs = bamSwitch($obj->elseifs);
            $obj->else = bamSwitch($obj->else);
            break;
        case "Stmt_Interface":
            $obj->name = is_string($obj->name) ?
              Down(Offset($obj->attributes["startFilePos"] + 1,
                  $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
              bamSwitch($obj->name);
            $obj->extends = bamSwitch($obj->extends);
            $obj->stmts = bamSwitch($obj->stmts);
            break;
        case "Stmt_Namespace":
            $obj->name = bamSwitch($obj->name);
            $obj->stmts = bamSwitch($obj->stmts);
            break;
        case "Stmt_Property":
            $obj->props = bamSwitch($obj->props);
            $obj->type = is_string($obj->type) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->type);
            break;
        case "Stmt_PropertyProperty":
            $obj->name = is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name);
            $obj->default = bamSwitch($obj->default);
            break;
        case "Stmt_StaticVar":
            $obj->var = bamSwitch($obj->var);
            $obj->default = bamSwitch($obj->default);
            break;
        case "Stmt_Switch":
            $obj->cond = bamSwitch($obj->cond);
            $obj->cases = bamSwitch($obj->cases);
            break;
        case "Stmt_Trait":
            $obj->name = is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name);
            $obj->stmts = bamSwitch($obj->stmts);
            break;
        case "Stmt_TraitUse":
            $obj->traits = bamSwitch($obj->traits);
            $obj->adaptations = bamSwitch($obj->adaptations);
            break;
        case "Stmt_TryCatch":
            $obj->stmts = bamSwitch($obj->stmts);
            $obj->catches = bamSwitch($obj->catches);
            $obj->finally = bamSwitch($obj->finally);
            break;
        case "Stmt_Use":
            $obj->uses = bamSwitch($obj->uses);
            break;
        case "Stmt_UseUse":
            $obj->name = bamSwitch($obj->name);
            $obj->alias = is_string($obj->alias) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->alias);
            break;
        case "Stmt_TraitUseAdaptation_Alias":
            $obj->trait = bamSwitch($obj->trait);
            $obj->method = is_string($obj->method) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->method);
            $obj->newName = is_string($obj->newName) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->newName);
            break;
        case "Stmt_TraitUseAdaptation_Precedence":
            $obj->trait = bamSwitch($obj->trait);
            $obj->method = is_string($obj->method) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->method);
            $obj->insteadOf = bamSwitch($obj->insteadOf);
            break;
        case "Arg":
            $obj->value = bamSwitch($obj->value);
            break;
        case "Const":
            $obj->name = is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name);
            $obj->value = bamSwitch($obj->value);
            break;
        case "Identifier":
            $obj->name = Down(Offset($obj->attributes["startFilePos"],
                    $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] + 1));
            break;
        case "NullableType":
            $obj->type = is_string($obj->type) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->type);
            break;
        case "Param":
        
            $obj->type = is_string($obj->type) ?
                Down(Offset($obj->attributes["startFilePos"] + 1,
                    $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                bamSwitch($obj->type);
            $obj->var = bamSwitch($obj->var);
            $obj->default = bamSwitch($obj->default);
            $obj->flags = bamSwitch($obj->flags);
            break;
        case "UnionType":
            $obj->types = bamSwitch($obj->types);
            break;
        default:
            if (substr($type, 0, 13) == "Expr_AssignOp") {
                $obj->var = bamSwitch($obj->var);
                $obj->expr = bamSwitch($obj->expr);
            } else if (substr($type, 0, 13) == "Expr_BinaryOp") {
                $obj->left = bamSwitch($obj->left);
                $obj->right = bamSwitch($obj->right);
            } else if (substr($type, 0, 13) == "Expr_Cast") {
                $obj->expr = bamSwitch($obj->expr);
            } else if (substr($type, 0, 4) == "Name") { //unsure on parts
                $obj->parts = is_string($obj->parts) ? Down(Offset($obj->attributes["startFilePos"] + 1,
                  $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                  (is_array($obj->parts) ? handleArr($obj->parts) : bamSwitch($obj->parts));
            } else if (substr($type, 0, 17) == "Scalar_MagicConst") {
              // don't do anything
            }
            else{
            }
    }
    return $obj;
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