<?php

require_once __DIR__ . "/../../bam.php";
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

// Source string    interpreted string
// src\nStr\ning => src
//                  str
//                  ing
// Given an $inContext NULL | Up(Interval($start, ....)) on the interpreted string
// Given a $length on the interpreted string
// Given $escaped an array of [position in source string, oldLength] of all the positions of characters that reduced to a single character
// Computes [$deltaStart, $deltaLength] such that the interval Offset($start, $length) valid in interpreted string
// is transformed to some valid Offset($start + $deltaStart, $length + $deltaLength) valid in source string.
function computeDeltaOffset($inContext, $length, &$escaped) {
  $inOffset = $inContext === NULL ? 0 : $inContext->keyOrOffset->count;
  $deltaLength = 0;
  $deltaStart = 0;
  $length = MinUndefined($length, $inContext === NULL ? NULL : $inContext->keyOrOffset->newLength);
  forEach($escaped as $key => list($position, $positionDeltaLength)) {
    if($position - $deltaStart < $inOffset) {
      $deltaStart += $positionDeltaLength;
    } else {
      if(LessThanUndefined($position - $deltaStart - $deltaLength, PlusUndefined($inOffset, $length))) {
        $deltaLength += $positionDeltaLength;
      }
    }
  }
  return [$deltaStart, $deltaLength];
}

foreach ([__DIR__ . '/../../../autoload.php', __DIR__ . '/../vendor/autoload.php'] as $file) {
    if (file_exists($file)) {
        require $file;
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

function makeBam($stmts) {
    $bamArr = [];
    foreach ($stmts as $stmt) {
        array_push($bamArr, bamSwitch($stmt));
    }
    return Create($bamArr);
}

function handleArr($exprs) {
    //echo "\n\nhandleExprs\n\n";
    if(!is_array($exprs)) {
        return Create($exprs);
    }
    $exprArr = [];
    //print_r($exprs);
    foreach ($exprs as $expr) {
        //echo print_r($expr);
        array_push($exprArr, bamSwitch($expr));
    }
    return Create($exprArr);
}

function bamSwitch($obj) { //should i go through arrays and bam items, some things with names need to be checked
    if ($obj == null) {
        return null;
    }
    if (is_string($obj)) {
        return $obj;
        //what to do here
    }
    $type = $obj->getType();
    switch ($type) {
        case "Stmt_Expression":
        case "Stmt_Return":
        case "Stmt_Throw":
            $new = Create([
                "expr" => bamSwitch($obj->expr),
                "attributes" => Create($obj->attributes)
            ], $obj);
            /*$new = Create(\PhpParser\Node\Stmt\Expression::class);
            //$new = \PhpParser\Node\Stmt\Expression;
            $obj->expr; //will be its own thing to check
            $obj->attributes; //check for comments*/
            break;
        case "Expr_Assign":
            $new = Create([
                "var" => bamSwitch($obj->var),
                "expr" => bamSwitch($obj->expr),
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
                    bamSwitch($obj->name),
                "attributes" => Create($obj->attributes)
            ], $obj);
            /*$new = Create(\PhpParser\Node\Expr\Variable::class);
            //$new = \PhpParser\Node\Expr\Variable;
            $obj->name; // either string or expr
            $obj->attributes; //check for comments*/
            break;
        case "Scalar_LNumber":
        case "Scalar_DNumber":
            $new = Create([
                "value" => Custom(Down(Offset($obj->attributes["startFilePos"],
                    $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] + 1)),
                    function ($x) {return intval($x);},
                    function ($edit, $oldInput, $oldOutput) {
                        $newNum = $edit->model;
                        // TODO to handle the strings starting 0x and with 0
                        return Create(strval($newNum));
                    },
                    "Number parse"
                    ),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Scalar_String":
            $l = NULL;
            $l = Lens(
              function($sourceString) use ($obj) {
                //echo "sourceString:$sourceString\n";
                return $obj->value; // Correct implementation !
              },
              function($editAction, $sourceString, $string) {
                $quoteType = $sourceString[0];
                // Let's 1) find the indices where characters were contracted:
                //  
                // 2) change offsets in edit action so that they are inserting or deleting at the correct place
                // 3) change the inserted strings so that they 
                $stringSyntax = $quoteType == "'" || $quoteType == '"';
                $docSyntax = !$stringSyntax;
                $escaped = [[]];
                $innerStringStart = 1;
                $innerStringLength = strlen($sourceString) - 2;
                $is_heredoc = false;
                $is_nowdoc = false;
                if($docSyntax) {
                  $is_nowdoc = $sourceString[3] === "'";
                  $is_heredoc = !$is_nowdoc;
                  $innerStringStart = strpos($sourceString, "\n") + 1;
                  $startTagPosition = $is_nowdoc ? 4 : 3;
                  $endTag = substr($sourceString, $startTagPosition,
                         ($sourceString[$innerStringStart - 2] == "\r" ? $innerStringStart - 3 : $innerStringStart - 2) - $startTagPosition - ($is_nowdoc ? 1 : 0) + 1);
                  $endTagPosition = strlen($sourceString) - strlen($endTag);
                  $endOfStringContent = $endTagPosition - 1;
                  if($sourceString[$endOfStringContent] === "\n") $endOfStringContent--;
                  if($sourceString[$endOfStringContent] === "\r") $endOfStringContent--;
                  $innerStringLength = $endOfStringContent - $innerStringStart + 1;
                }
                $innerString = substr($sourceString, $innerStringStart, $innerStringLength);
                $possibleEscapes =
                  $quoteType == "'" ? "/\\\\\\\\|\\\\'/" : (
                  $quoteType == "\"" || $is_heredoc ?  "/\\\\\\\\|\\\\[nrtvef\$\"]|\\\\[0-7]{1,3}|\\\\x[0-9A-Fa-f]{1,2}|\\\\u\\{[0-9A-Fa-f]+\\}/" : (
                    "" // nowdoc
                  ));
                if($possibleEscapes != "") {
                  preg_match_all($possibleEscapes, $innerString, $escaped, PREG_OFFSET_CAPTURE);
                }
                //echo "positions in <$innerString> : ".uneval($escaped), "\n";
                // Contains an array of [position, delta length at this position]
                $escaped = array_map(function($matchPosition) {
                  $lengthOfResultingChar = 1;
                  if(substr($matchPosition[0], 0, 2) == "\\u") {
                    if(class_exists("IntlChar")) {
                      $lengthOfResultingChar = strlen(IntlChar::chr($matchPosition[0]));
                    } else {
                      $lengthOfResultingChar = strlen(json_decode(
                        '\u'.substr($matchPosition[0], 3, strlen($matchPosition[0])-4)));
                    }
                  }
                  return [$matchPosition[1], strlen($matchPosition[0]) - $lengthOfResultingChar];
                }, $escaped[0]);
                //echo "positions in <$innerString> : ".uneval($escaped), "\n";
                $convertConst =
                  $quoteType == "'" ? function($v) {
                    return str_replace(['\\', "'"], [ "\\\\","\\'"], $v);
                  } : (
                  $quoteType == "\"" ? function($v) {
                    return str_replace(["\\",  "\n", "\r", "\t", "\v", "\e", "\f", "$",  "\""],
                                       ["\\\\","\\n","\\r","\\t","\\v","\\e","\\f","\\$","\\\""], $v);
                  } : (
                  $is_heredoc ? function($v) { // We could optimize by replacing "\\" by "\\\\" only if necessary.
                    return str_replace(["\\",  "\v", "\e", "\f", "$"],
                                       ["\\\\","\\v","\\e","\\f","\\$"], $v);
                  } : ( function($v) {
                    return $v;
                  })));
                //echo "Calling Postmap on ",uneval($editAction, ""),"\n";
                $editActionOnOriginal = postMap($editAction, function($edit, $inContext) use(&$escaped, &$convertConst) {
                  if(isConst($edit)) { // Works with Prepend and Append the same way.
                    $v = valueIfConst($edit);
                    $newV = $convertConst($v);
                    //echo "transforming ".uneval($v)," => ",uneval($newV)," (context is ".uneval($inContext).")\n";
                    return isEditAction($edit) ? Create($newV) : $newV;
                  } else if(isDown($edit)) {
                    // Need to offset the start and end positions since they operate on input.
                    list($deltaStart, $deltaLength) = computeDeltaOffset(Up($edit->keyOrOffset, $inContext), NULL, $escaped);
                    $finalEdit = SameDownAs($edit, Offset($edit->keyOrOffset->count + $deltaStart, PlusUndefined($edit->keyOrOffset->newLength, $deltaLength)), $edit->subAction);
                    //echo "transforming ".uneval($edit)," => ",uneval($finalEdit)," (context is ".uneval($inContext).")\n";
                    return $finalEdit;
                  } else if(isReplace($edit)) {
                    $inCount = $edit->replaceCount;
                    $outCount = $edit->count;
                    list($deltaStart, $deltaLength) = computeDeltaOffset($inContext, $inCount, $escaped);
                    $newFirst = Up(Interval($deltaStart), $edit->first);
                    $newSecond = Up(Interval($deltaStart), $edit->second);
                    $newReplaceInCount = $edit->replaceCount + $deltaLength;
                    $newInCount = $inContext !== NULL ? $inContext->keyOrOffset->newLength : NULL;
                    //echo "outLength(", uneval($newFirst), ", ", $newInCount, ")\n";
                    $newOutCount = outLength($newFirst, $newInCount);
                    //echo "newOutCount == $newOutCount\n";
                    if($newOutCount === NULL) $newOutCount = $outCount;
                    $finalEdit = Concat($newOutCount, $newFirst, $newSecond, $newReplaceInCount);
                    //echo "transforming ".uneval($edit)," => ",uneval($finalEdit)," (context is ".uneval($inContext).")\n";
                    return $finalEdit;
                  }
                  return $edit;
                });
                $finalEdit = Keep($innerStringStart, $editActionOnOriginal);
                //echo "Result is ",uneval($finalEdit, ""),"\n";
                if($is_heredoc || $is_nowdoc) {
                  //echo "Test for injections\n";
                  // Test for injections, and prevent them.
                  $finalSource = apply($finalEdit, $sourceString);
                  $tagPrefix = "";
                  
                  while(strpos($finalSource, "\n".$tagPrefix.$endTag.";") !== false) {
                    //echo "One injection found\n";
                    // We need to change the EOT. We prefix it with
                    $tagPrefix = "A".$tagPrefix;
                  }
                  if($tagPrefix !== "") {
                    $finalEdit = merge(
                      merge(
                        $finalEdit,
                        Keep($startTagPosition, Prepend(strlen($tagPrefix), $tagPrefix))
                      ),
                      Keep($endTagPosition, Prepend(strlen($tagPrefix), $tagPrefix)));
                    //echo "Final result is ",uneval($finalEdit, ""),"\n";
                    $finalSource = apply($finalEdit, $sourceString);
                    //echo "Final source is '$finalSource'\n";
                  }
                }
                return $finalEdit;
              },
              "Source string to string" 
            );
            // We replace the value by the provided edit action
            $new = Create([
                "value" => Custom(Down(Interval($obj->attributes["startFilePos"],
                    $obj->attributes["endFilePos"] + 1)),
                    $l),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Scalar_EncapsedStringPart":
            $new = Create([
                "value" => Custom(Down(Offset($obj->attributes["startFilePos"],
                    $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] + 1)),
                    function($x) {return str_replace("\\n", "\n", $x);},
                    function($editAction, $x, $oldResult) {
                        return $editAction; //str_replace("\n", "\\n", $x);
                    },
                    "Encapsulated String process"),
                // TODO create edit action that does transformation for all special characters
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Scalar_MagicConst_File":
            $new = $obj;
            break;
        case "Stmt_InlineHTML":
            $new = Create([
                "value" => Custom(Down(Offset($obj->attributes["startFilePos"],
                    $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] + 1)),
                    function($x) {return str_replace("\\n", "\n", $x);},
                    function($editAction, $x, $oldResult) {
                        return $editAction; //str_replace("\n", "\\n", $x);
                    },
                    "inline HTML String process"),
                // TODO create edit action that does transformation for all special characters
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        // can also delete the start, keep length
        // Delete($obj->attributes["startFilePos"] + 1, Keep($obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1), Down(Offset(0, 0))
        case "Stmt_Echo":
            $new = Create([
                "exprs" => handleArr($obj->exprs),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Array": // might need to do bamswitch stuff on items
            $new = Create([
                "items" => handleArr($obj->items),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_ArrayDimFetch":
            $new = Create([
                "var" => bamSwitch($obj->var),
                "dim" => bamSwitch($obj->dim),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_ArrayItem":
            $new = Create([
                "key" => bamSwitch($obj->key),
                "value" => bamSwitch($obj->value),
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
                "returnType" => bamSwitch($obj->returnType),
                "expr" => bamSwitch($obj->expr),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_AssignRef":
            $new = Create([
                "var" => bamSwitch($obj->var),
                "expr" => bamSwitch($obj->expr),
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
                "expr" => bamSwitch($obj->expr),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_ClassConstFetch":
        case "Expr_StaticPropertyFetch":
            $new = Create([
                "class" => bamSwitch($obj->class),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Closure":
            $new = Create([
                "static" => Create($obj->static),
                "byRef" => Create($obj->byRef),
                "params" => Create($obj->params),
                "uses" => Create($obj->uses),
                "returnType" => bamSwitch($obj->returnType),
                "stmts" => handleArr($obj->stmts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_ClosureUse":
            $new = Create([
                "var" => bamSwitch($obj->var),
                "byRef" => Create($obj->byRef),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_ConstFetch":
            $new = Create([
                "name" => bamSwitch($obj->name),
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
                "name" => bamSwitch($obj->name),
                "args" => handleArr($obj->args),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Include":
            $new = Create([
                "expr" => bamSwitch($obj->expr),
                "type" => Create($obj->type),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Instanceof":
            $new = Create([
                "expr" => bamSwitch($obj->expr),
                "class" => bamSwitch($obj->class),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Isset":
        case "Stmt_Global":
        case "Stmt_Static":
        case "Stmt_Unset":
            $new = Create([
                "vars" => handleArr($obj->vars),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_List": //figure out what items are
            $new = Create([
                "items" => handleArr($obj->items),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_MethodCall":
            $new = Create([
                "var" => bamSwitch($obj->var),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name),
                "args" => handleArr($obj->args),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_New":
            $new = Create([
                "class" => bamSwitch($obj->class),
                "args" => handleArr($obj->args),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_PostDec":
        case "Expr_PostInc":
        case "Expr_PreDec":
        case "Expr_PreInc":
            $new = Create([
                "var" => bamSwitch($obj->var),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_PropertyFetch":
            $new = Create([
                "var" => bamSwitch($obj->var),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_ShellExec":
        case "Scalar_Encapsed":
            $new = Create([
                "parts" => handleArr($obj->parts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_StaticCall":
            $new = Create([
                "class" => bamSwitch($obj->class),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name),
                "args" => handleArr($obj->args),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Ternary":
            $new = Create([
                "cond" => bamSwitch($obj->cond),
                "if" => bamSwitch($obj->if),
                "else" => bamSwitch($obj->else),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Expr_Yield":
            $new = Create([
                "key" => bamSwitch($obj->key),
                "value" => bamSwitch($obj->value),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Break":
        case "Stmt_Continue":
            $new = Create([
                "num" => bamSwitch($obj->num),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Case":
        case "Stmt_Do":
        case "Stmt_ElseIf":
        case "Stmt_While":
            $new = Create([
                "cond" => bamSwitch($obj->cond),
                "stmts" => handleArr($obj->stmts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Catch":
            $new = Create([
                "objName" => Create($type),
                "types" => handleArr($obj->types),
                "var" => bamSwitch($obj->var),
                "stmts" => handleArr($obj->stmts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Class":
            $new = Create([
                "objName" => Create($type),
                "flags" => Create($obj->flags),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name),
                "implements" => handleArr($obj->implements),
                "extends" => bamSwitch($obj->extends),
                "stmts" => handleArr($obj->stmts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_ClassConst":
            $new = Create([
                "objName" => Create($type),
                "flags" => Create($obj->flags),
                "consts" => handleArr($obj->consts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_ClassMethod": //params?? handleArr??
            $new = Create([
                "objName" => Create($type),
                "flags" => Create($obj->flags),
                "byRef" => Create($obj->byRef),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name),
                "params" => Create($obj->params),
                "returnType" => bamSwitch($obj->returnType),
                "stmts" => handleArr($obj->stmts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Const":
            $new = Create([
                "objName" => Create($type),
                "consts" => handleArr($obj->consts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Declare":
            $new = Create([
                "objName" => Create($type),
                "declares" => handleArr($obj->declares),
                "stmts" => handleArr($obj->stmts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_DeclareDeclare":
            $new = Create([
                "objName" => Create($type),
                "key" => is_string($obj->key) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->key),
                "value" => bamSwitch($obj->value),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Else":
        case "Stmt_Finally":
            $new = Create([
                "objName" => Create($type),
                "stmts" => handleArr($obj->stmts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_For":
            $new = Create([
                "objName" => Create($type),
                "init" => handleArr($obj->init),
                "cond" => handleArr($obj->cond),
                "loop" => handleArr($obj->loop),
                "stmts" => handleArr($obj->stmts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Foreach":
            $new = Create([
                "objName" => Create($type),
                "expr" => bamSwitch($obj->expr),
                "keyVar" => bamSwitch($obj->keyVar),
                "byRef" => Create($obj->byRef),
                "valueVar" => bamSwitch($obj->valueVar),
                "stmts" => handleArr($obj->stmts)
            ], $obj);
            break;
        case "Stmt_Function":
            $new = Create([
                "objName" => Create($type),
                "byRef" => Create($obj->byRef),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name),
                "params" => Create($obj->params),
                "returnType" => bamSwitch($obj->returnType),
                "stmts" => handleArr($obj->stmts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_GroupUse":
            $new = Create([
                "objName" => Create($type),
                "type" => Create($obj->type),
                "prefix" => bamSwitch($obj->prefix),
                "uses" => handleArr($obj->uses),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_HaltCompiler":
            $new = Create([
                "objName" => Create($type),
                "remaining" => Down(Offset($obj->attributes["startFilePos"] + 1,
                    $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_If":
            $new = Create([
                "objName" => Create($type),
                "cond" => bamSwitch($obj->cond),
                "stmts" => handleArr($obj->stmts),
                "elseifs" => handleArr($obj->elseifs),
                "else" => bamSwitch($obj->else),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Interface":
            $new = Create([
                "objName" => Create($type),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name),
                "extends" => handleArr($obj->extends),
                "stmts" => handleArr($obj->stmts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Namespace":
            $new = Create([
                "objName" => Create($type),
                "name" => bamSwitch($obj->name),
                "stmts" => handleArr($obj->stmts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Property":
            $new = Create([
                "objName" => Create($type),
                "flags" => Create($obj->flags),
                "props" => handleArr($obj->props),
                "type" => is_string($obj->type) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->type),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_PropertyProperty":
            $new = Create([
                "objName" => Create($type),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name),
                "default" => bamSwitch($obj->default),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_StaticVar":
            $new = Create([
                "objName" => Create($type),
                "var" => bamSwitch($obj->var),
                "default" => bamSwitch($obj->default),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Switch":
            $new = Create([
                "objName" => Create($type),
                "cond" => bamSwitch($obj->cond),
                "cases" => handleArr($obj->cases),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Trait":
            $new = Create([
                "objName" => Create($type),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name),
                "stmts" => handleArr($obj->stmts),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_TraitUse":
            $new = Create([
                "objName" => Create($type),
                "traits" => handleArr($obj->traits),
                "adaptations" => handleArr($obj->adaptations),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_TryCatch":
            $new = Create([
                "objName" => Create($type),
                "stmts" => handleArr($obj->stmts),
                "catches" => handleArr($obj->catches),
                "finally" => bamSwitch($obj->finally),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_Use":
            $new = Create([
                "objName" => Create($type),
                "type" => Create($obj->type),
                "uses" => handleArr($obj->uses),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_UseUse":
            $new = Create([
                "objName" => Create($type),
                "type" => Create($obj->type),
                "name" => bamSwitch($obj->name),
                "alias" => is_string($obj->alias) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->alias),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_TraitUseAdaptation_Alias":
            $new = Create([
                "objName" => Create($type),
                "trait" => bamSwitch($obj->trait),
                "method" => is_string($obj->method) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->method),
                "newModifier" => Create($obj->newModifier),
                "newName" => is_string($obj->newName) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->newName),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Stmt_TraitUseAdaptation_Precedence":
            $new = Create([
                "objName" => Create($type),
                "trait" => bamSwitch($obj->trait),
                "method" => is_string($obj->method) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->method),
                "insteadOf" => handleArr($obj->insteadOf),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Arg":
            $new = Create([
                "objName" => Create($type),
                "value" => bamSwitch($obj->value),
                "byRef" => Create($obj->byRef),
                "unpack" => Create($obj->unpack),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Const":
            $new = Create([
                "objName" => Create($type),
                "name" => is_string($obj->name) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->name),
                "value" => bamSwitch($obj->value),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Identifier":
            $new = Create([
                "objName" => Create($type),
                "name" => Down(Offset($obj->attributes["startFilePos"],
                    $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] + 1)),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "NullableType":
            $new = Create([
                "objName" => Create($type),
                "type" => is_string($obj->type) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->type),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "Param":
            $new = Create([
                "objName" => Create($type),
                "type" => is_string($obj->type) ?
                    Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                    bamSwitch($obj->type),
                "byRef" => Create($obj->byRef),
                "variadic" => Create($obj->variadic),
                "var" => bamSwitch($obj->var),
                "default" => bamSwitch($obj->var),
                "flags" => handleArr($obj->flags),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        case "UnionType":
            $new = Create([
                "objName" => Create($type),
                "types" => handleArr($obj->types),
                "attributes" => Create($obj->attributes)
            ], $obj);
            break;
        default:
            if (substr($type, 0, 13) == "Expr_AssignOp") {
                $new = Create([
                    "objName" => Create($type),
                    "var" => bamSwitch($obj->var),
                    "expr" => bamSwitch($obj->expr),
                    "attributes" => Create($obj->attributes)
                ], $obj);
            } else if (substr($type, 0, 13) == "Expr_BinaryOp") {
                $new = Create([
                    "objName" => Create($type),
                    "left" => bamSwitch($obj->left),
                    "right" => bamSwitch($obj->right),
                    "attributes" => Create($obj->attributes)
                ], $obj);
            } else if (substr($type, 0, 13) == "Expr_Cast") {
                $new = Create([
                    "objName" => Create($type),
                    "expr" => bamSwitch($obj->expr),
                    "attributes" => Create($obj->attributes)
                ], $obj);
            } else if (substr($type, 0, 4) == "Name") { //unsure on parts
                $new = Create([
                    "objName" => Create($type),
                    "parts" => is_string($obj->parts) ? Down(Offset($obj->attributes["startFilePos"] + 1,
                        $obj->attributes["endFilePos"] - $obj->attributes["startFilePos"] - 1)) :
                        (is_array($obj->parts) ? handleArr($obj->parts) : bamSwitch($obj->parts)),
                    "attributes" => Create($obj->attributes)
                ], $obj);
            } else if (substr($type, 0, 17) == "Scalar_MagicConst") {
                $new = Create([
                    "objName" => Create($type),
                    "attributes" => Create($obj->attributes)
                ], $obj);
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