<?php
/*
 * This file is part of Fenom.
 *
 * (c) 2013 Ivan Shalganov
 *
 * For the full copyright and license information, please view the license.md
 * file that was distributed with this source code.
 */
namespace Fenom;
use Fenom;

/**
 * Template compiler
 *
 * @package    Fenom
 * @author     Ivan Shalganov <a.cobest@gmail.com>
 */
class Template extends Render {

    /**
     * Disable array parser.
     */
    const DENY_ARRAY = 1;
    /**
     * Disable modifier parser.
     */
    const DENY_MODS = 2;

    /**
     * Template was extended
     */
    const DYNAMIC_EXTEND = 0x1000;
    const EXTENDED = 0x2000;
    const DYNAMIC_BLOCK = 0x4000;

    /**
     * @var int shared counter
     */
    public $i = 1;
    /**
     * Template PHP code
     * @var string
     */
    public $_body;

    /**
     * @var array of macros
     */
    public $macros = array();

    /**
     * @var array of blocks
     */
    public $blocks = array();

    public $uses = array();

    public $parents = array();

    public $_extends;
    public $_extended = false;
    public $_compatible;

    /**
     * Call stack
     * @var Scope[]
     */
    private $_stack = array();

    /**
     * Template source
     * @var string
     */
    private $_src;
    /**
     * @var int
     */
    private $_line = 1;
    private $_post = array();
    /**
     * @var bool
     */
    private $_ignore = false;

    private $_filter = array();

    /**
     * Just factory
     *
     * @param \Fenom $fenom
     * @param $options
     * @return Template
     */
    public static function factory(Fenom $fenom, $options) {
        return new static($fenom, $options);
    }

    /**
     * @param Fenom $fenom Template storage
     * @param int $options
     * @return \Fenom\Template
     */
    public function __construct(Fenom $fenom, $options) {
        $this->_fenom = $fenom;
        $this->_options = $options;
    }

    /**
     * Get tag stack size
     * @return int
     */
    public function getStackSize() {
        return count($this->_stack);
    }

    /**
     * Load source from provider
     * @param string $name
     * @param bool $compile
     * @return $this
     */
    public function load($name, $compile = true) {
        $this->_name = $name;
        if($provider = strstr($name, ":", true)) {
            $this->_scm = $provider;
            $this->_base_name = substr($name, strlen($provider) + 1);
        } else {
            $this->_base_name = $name;
        }
        $this->_provider = $this->_fenom->getProvider($provider);
        $this->_src = $this->_provider->getSource($this->_base_name, $this->_time);
        if($compile) {
            $this->compile();
        }
        return $this;
    }

    /**
     * Load custom source
     * @param string $name template name
     * @param string $src template source
     * @param bool $compile
     * @return \Fenom\Template
     */
    public function source($name, $src, $compile = true) {
        $this->_name = $name;
        $this->_src = $src;
        if($compile) {
            $this->compile();
        }
        return $this;
    }

    /**
     * Convert template to PHP code
     *
     * @throws CompileException
     */
    public function compile() {
        $end = $pos = 0;
        while(($start = strpos($this->_src, '{', $pos)) !== false) { // search open-symbol of tags
            switch($this->_src[$start + 1]) { // check next character
                case "\n": case "\r": case "\t": case " ": case "}": // ignore the tag
                $pos = $start + 1;
                continue 2;
                case "*": // if comments
                    $end = strpos($this->_src, '*}', $start) + 1; // find end of the comment block
                    if($end === false) {
                        throw new CompileException("Unclosed comment block in line {$this->_line}", 0, 1, $this->_name, $this->_line);
                    }
                    $this->_appendText(substr($this->_src, $pos, $start - $pos));
                    $comment = substr($this->_src, $start, $end - $start); // read the comment block for processing
                    $this->_line += substr_count($comment, "\n"); // count lines in comments
                    unset($comment); // cleanup
                    $pos = $end + 1;
                    continue 2;
            }
            $frag = substr($this->_src, $pos, $start - $pos);  // variable $frag contains chars after previous '}' and current '{'
            $this->_appendText($frag);

            $from = $start;
            reparse: { // yep, i use goto operator. For this algorithm it is good choice
                $end = strpos($this->_src, '}', $from); // search close-symbol of the tag
                if($end === false) { // if unexpected end of template
                    throw new CompileException("Unclosed tag in line {$this->_line}", 0, 1, $this->_name, $this->_line);
                }
                $tag = substr($this->_src, $start, $end - $start + 1); // variable $tag contains fenom tag '{...}'

                $_tag = substr($tag, 1, -1); // strip delimiters '{' and '}'

                if($this->_ignore) { // check ignore
                    if($_tag === '/ignore') { // turn off ignore
                        $this->_ignore = false;
                    } else { // still ignore
                        $this->_appendText($tag);
                    }
                    $pos = $start + strlen($tag);
                    continue;
                } else {
                    $tokens = new Tokenizer($_tag); // tokenize the tag
                    if($tokens->isIncomplete()) { // all strings finished?
                        $from = $end + 1;
                        goto reparse; // need next close-symbol
                    }
                    $this->_appendCode( $this->_tag($tokens) , $tag); // start the tag lexer
                    $pos = $end + 1; // move search-pointer to end of the tag
                    if($tokens->key()) { // if tokenizer have tokens - throws exceptions
                        throw new CompileException("Unexpected token '".$tokens->current()."' in {$this} line {$this->_line}, near '{".$tokens->getSnippetAsString(0,0)."' <- there", 0, E_ERROR, $this->_name, $this->_line);
                    }

                }
            }
            unset($frag, $_tag, $tag); // cleanup
        }
        gc_collect_cycles();
        $this->_appendText(substr($this->_src, $end ? $end + 1 : 0));
        if($this->_stack) {
            $_names = array();
            $_line = 0;
            foreach($this->_stack as $scope) {
                if(!$_line) {
                    $_line = $scope->line;
                }
                $_names[] = '{'.$scope->name.'} opened on line '.$scope->line;
            }
            throw new CompileException("Unclosed tag".(count($_names) == 1 ? "" : "s").": ".implode(", ", $_names), 0, 1, $this->_name, $_line);
        }
        $this->_src = ""; // cleanup
        if($this->_post) {
            foreach($this->_post as $cb) {
                call_user_func_array($cb, array(&$this->_body, $this));
            }
        }
    }

    /**
     * Generate temporary internal template variable
     * @return string
     */
    public function tmpVar() {
        return '$t'.($this->i++);
    }

    /**
     * Append plain text to template body
     *
     * @param string $text
     */
    private function _appendText($text) {
        $this->_line += substr_count($text, "\n");
        if($this->_filter) {
            if(strpos($text, "<?") === false) {
                $this->_body .= $text;
            } else {
                $fragments = explode("<?", $text);
                foreach($fragments as &$fragment) {
                    if($fragment) {
                        foreach($this->_filter as $filter) {
                            $fragment = call_user_func($filter, $fragment);
                        }
                    }
                }
                $this->_body .= implode('<?php echo "<?"; ?>', $fragments);
            }
        } else {
            $this->_body .= str_replace("<?", '<?php echo "<?"; ?>'.PHP_EOL, $text);
        }
    }

    /**
     * Append PHP_EOL after each '?>'
     * @param int $code
     * @return string
     */
    private function _escapeCode($code) {
        $c = "";
        foreach(token_get_all($code) as $token) {
            if(is_string($token)) {
                $c .= $token;
            } elseif($token[0] == T_CLOSE_TAG) {
                $c .= $token[1].PHP_EOL;
            } else {
                $c .= $token[1];
            }
        }
        return $c;
    }

    /**
     * Append PHP code to template body
     *
     * @param string $code
     * @param $source
     */
    private function _appendCode($code, $source) {

        if(!$code) {
            return;
        } else {
            $this->_line += substr_count($source, "\n");
            if(strpos($code, '?>') !== false) {
                $code = $this->_escapeCode($code); // paste PHP_EOL
            }
            $this->_body .= "<?php\n/* {$this->_name}:{$this->_line}: {$source} */\n $code ?>".PHP_EOL;
        }
    }

    /**
     * @param callable[] $cb
     */
    public function addPostCompile($cb) {
        $this->_post[] = $cb;
    }

    /**
     * Return PHP code of template
     *
     * @return string
     */
    public function getBody() {
        return $this->_body;
    }

    /**
     * Return PHP code for saving to file
     *
     * @return string
     */
    public function getTemplateCode() {
        return "<?php \n".
        "/** Fenom template '".$this->_name."' compiled at ".date('Y-m-d H:i:s')." */\n".
        "return new Fenom\\Render(\$fenom, ".$this->_getClosureSource().", ".var_export(array(
                "options" => $this->_options,
                "provider" => $this->_scm,
                "name" => $this->_name,
                "base_name" => $this->_base_name,
                "time" => $this->_time,
                "depends" => $this->_depends
            ), true).");\n";
    }

    /**
     * Return closure code
     * @return string
     */
    private function _getClosureSource() {
        return "function (\$tpl) {\n?>{$this->_body}<?php\n}";
    }

    /**
     * Runtime execute template.
     *
     * @param array $values input values
     * @throws CompileException
     * @return Render
     */
    public function display(array $values) {
        if(!$this->_code) {
            // evaluate template's code
            eval("\$this->_code = ".$this->_getClosureSource().";");
            if(!$this->_code) {
                throw new CompileException("Fatal error while creating the template");
            }
        }
        return parent::display($values);

    }

    /**
     * Add depends from template
     * @param Render $tpl
     */
    public function addDepend(Render $tpl) {
        $this->_depends[$tpl->getScm()][$tpl->getName()] = $tpl->getTime();
    }

    /**
     * Execute template and return result as string
     * @param array $values for template
     * @throws CompileException
     * @return string
     */
    public function fetch(array $values) {
        if(!$this->_code) {
            eval("\$this->_code = ".$this->_getClosureSource().";");
            if(!$this->_code) {
                throw new CompileException("Fatal error while creating the template");
            }
        }
        return parent::fetch($values);
    }

    private function _print($data) {
        if($this->_options & Fenom::AUTO_ESCAPE) {
            return "echo htmlspecialchars($data, ENT_COMPAT, 'UTF-8')";
        } else {
            return "echo $data";
        }
    }
    /**
     * Internal tags router
     * @param Tokenizer $tokens
     *
     * @throws SecurityException
     * @throws CompileException
     * @return string executable PHP code
     */
    private function _tag(Tokenizer $tokens) {
        try {
            if($tokens->is(Tokenizer::MACRO_STRING)) {
                if($tokens->current() === "ignore") {
                    $this->_ignore = true;
                    $tokens->next();
                    return '';
                } else {
                    return $this->_parseAct($tokens);
                }
            } elseif ($tokens->is('/')) {
                return $this->_end($tokens);
            } elseif ($tokens->is('#')) {
                return $this->_print($this->parseConst($tokens)).';';
            } else {
                return $code = $this->_print($this->parseExp($tokens)).";";
            }
        } catch (InvalidUsageException $e) {
            throw new CompileException($e->getMessage()." in {$this} line {$this->_line}", 0, E_ERROR, $this->_name, $this->_line, $e);
        } catch (\LogicException $e) {
            throw new SecurityException($e->getMessage()." in {$this} line {$this->_line}, near '{".$tokens->getSnippetAsString(0,0)."' <- there", 0, E_ERROR, $this->_name, $this->_line, $e);
        } catch (\Exception $e) {
            throw new CompileException($e->getMessage()." in {$this} line {$this->_line}, near '{".$tokens->getSnippetAsString(0,0)."' <- there", 0, E_ERROR, $this->_name, $this->_line, $e);
        }
    }

    /**
     * Close tag handler
     *
     * @param Tokenizer $tokens
     * @return mixed
     * @throws TokenizeException
     */
    private function _end(Tokenizer $tokens) {
        //return "end";
        $name = $tokens->getNext(Tokenizer::MACRO_STRING);
        $tokens->next();
        if(!$this->_stack) {
            throw new TokenizeException("Unexpected closing of the tag '$name', the tag hasn't been opened");
        }
        /** @var Scope $scope */
        $scope = array_pop($this->_stack);
        if($scope->name !== $name) {
            throw new TokenizeException("Unexpected closing of the tag '$name' (expecting closing of the tag {$scope->name}, opened on line {$scope->line})");
        }
        return $scope->close($tokens);
    }

    /**
     * Parse action {action ...} or {action(...) ...}
     *
     * @static
     * @param Tokenizer $tokens
     * @throws \LogicException
     * @throws TokenizeException
     * @return string
     */
    private function _parseAct(Tokenizer $tokens) {
        if($tokens->is(Tokenizer::MACRO_STRING)) {
            $action = $tokens->getAndNext();
        } else {
            return $this->_print($this->parseExp($tokens)).';'; // may be math and/or boolean expression
        }

        if($tokens->is("(", T_NAMESPACE, T_DOUBLE_COLON)) { // just invoke function or static method
            $tokens->back();
            return $this->_print($this->parseExp($tokens)).";";
        } elseif($tokens->is('.')) {
            $name = $tokens->skip()->get(Tokenizer::MACRO_STRING);
            if($action !== "macro") {
                $name = $action.".".$name;
            }
            return $this->parseMacro($tokens, $name);
        }

        if($act = $this->_fenom->getFunction($action)) { // call some function
            switch($act["type"]) {
                case Fenom::BLOCK_COMPILER:
                    $scope = new Scope($action, $this, $this->_line, $act, count($this->_stack), $this->_body);
                    $code = $scope->open($tokens);
                    if(!$scope->is_closed) {
                        array_push($this->_stack, $scope);
                    }
                    return $code;
                case Fenom::INLINE_COMPILER:
                    return call_user_func($act["parser"], $tokens, $this);
                case Fenom::INLINE_FUNCTION:
                    return call_user_func($act["parser"], $act["function"], $tokens, $this);
                case Fenom::BLOCK_FUNCTION:
                    $scope = new Scope($action, $this, $this->_line, $act, count($this->_stack), $this->_body);
                    $scope->setFuncName($act["function"]);
                    array_push($this->_stack, $scope);
                    return $scope->open($tokens);
                default:
                    throw new \LogicException("Unknown function type");
            }
        }

        for($j = $i = count($this->_stack)-1; $i>=0; $i--) { // call function's internal tag
            if($this->_stack[$i]->hasTag($action, $j - $i)) {
                return $this->_stack[$i]->tag($action, $tokens);
            }
        }
        if($tags = $this->_fenom->getTagOwners($action)) { // unknown template tag
            throw new TokenizeException("Unexpected tag '$action' (this tag can be used with '".implode("', '", $tags)."')");
        } else {
            throw new TokenizeException("Unexpected tag $action");
        }
    }

    /**
     * Parse expressions. The mix of math operations, boolean operations, scalars, arrays and variables.
     *
     * @static
     * @param Tokenizer $tokens
     * @param bool               $required
     * @throws \LogicException
     * @throws UnexpectedTokenException
     * @throws TokenizeException
     * @return string
     */
    public function parseExp(Tokenizer $tokens, $required = false) {
        $_exp = "";
        $brackets = 0;
        $term = false;
        $cond = false;
        while($tokens->valid()) {
            if(!$term && $tokens->is(Tokenizer::MACRO_SCALAR, '"', '`', T_ENCAPSED_AND_WHITESPACE)) {
                $_exp .= $this->parseScalar($tokens, true);
                $term = 1;
            } elseif(!$term && $tokens->is(T_VARIABLE)) {

                $pp = $tokens->isPrev(Tokenizer::MACRO_INCDEC);
                $_exp .= $this->parseVariable($tokens, 0, $only_var);
                if($only_var && !$pp) {
                    $term = 2;
                } else {
                    $term = 1;
                }
            } elseif(!$term && $tokens->is('#')) {
                $term = 1;
                $_exp .= $this->parseConst($tokens);
            } elseif(!$term && $tokens->is("(")) {
                $_exp .= $tokens->getAndNext();
                $brackets++;
                $term = false;
            } elseif($term && $tokens->is(")")) {
                if(!$brackets) {
                    break;
                }
                $brackets--;
                $_exp .= $tokens->getAndNext();
                $term = 1;
            } elseif(!$term && $tokens->is(T_STRING)) {
                if($tokens->isSpecialVal()) {
                    $_exp .= $tokens->getAndNext();
                } elseif($tokens->isNext("(")) {
                    $func = $this->_fenom->getModifier($tokens->current());
                    $tokens->next();
                    $_exp .= $func.$this->parseArgs($tokens);
                } else {
                    break;
                }
                $term = 1;
            } elseif(!$term && $tokens->is(T_ISSET, T_EMPTY)) {
                $_exp .= $tokens->getAndNext();
                if($tokens->is("(") && $tokens->isNext(T_VARIABLE)) {
                    $_exp .= $this->parseArgs($tokens);
                } else {
                    throw new TokenizeException("Unexpected token ".$tokens->getNext().", isset() and empty() accept only variables");
                }
                $term = 1;
            } elseif(!$term && $tokens->is(Tokenizer::MACRO_UNARY)) {
                if(!$tokens->isNext(T_VARIABLE, T_DNUMBER, T_LNUMBER, T_STRING, T_ISSET, T_EMPTY)) {
                    break;
                }
                $_exp .= $tokens->getAndNext();
                $term = 0;
            } elseif($tokens->is(Tokenizer::MACRO_BINARY)) {
                if(!$term) {
                    throw new UnexpectedTokenException($tokens);
                }
                if($tokens->isLast()) {
                    break;
                }
                if($tokens->is(Tokenizer::MACRO_COND)) {
                    if($cond) {
                        break;
                    }
                    $cond = true;
                } elseif ($tokens->is(Tokenizer::MACRO_BOOLEAN)) {
                    $cond = false;
                }
                $_exp .= " ".$tokens->getAndNext()." ";
                $term = 0;
            } elseif($tokens->is(Tokenizer::MACRO_INCDEC)) {
                if($term === 2) {
                    $term = 1;
                } elseif(!$tokens->isNext(T_VARIABLE)) {
                    break;
                }
                $_exp .= $tokens->getAndNext();
            } elseif($term && !$cond && !$tokens->isLast()) {
                if($tokens->is(Tokenizer::MACRO_EQUALS) && $term === 2) {
                    $_exp .= ' '.$tokens->getAndNext().' ';
                    $term = 0;
                } else {
                    break;
                }
            } else {
                break;
            }
        }

        if($term === 0) {
            throw new UnexpectedTokenException($tokens);
        }
        if($brackets) {
            throw new TokenizeException("Brackets don't match");
        }
        if($required && $_exp === "") {
            throw new UnexpectedTokenException($tokens);
        }
        return $_exp;
    }

    /**
     * Parse simple variable (without modifier etc)
     *
     * @param Tokenizer $tokens
     * @param int $options
     * @return string
     */
    public function parseVar(Tokenizer $tokens, $options = 0) {
        $var = $tokens->get(T_VARIABLE);
        $_var = '$tpl["'.substr($var, 1).'"]';
        $tokens->next();
        while($t = $tokens->key()) {
            if($t === "." && !($options & self::DENY_ARRAY)) {
                $key = $tokens->getNext();
                if($tokens->is(T_VARIABLE)) {
                    $key = "[ ".$this->parseVariable($tokens, self::DENY_ARRAY)." ]";
                } elseif($tokens->is(Tokenizer::MACRO_STRING)) {
                    if($tokens->isNext("(")) {
                        $key = "[".$this->parseExp($tokens)."]";
                    } else {
                        $key = '["'.$key.'"]';
                        $tokens->next();
                    }
                } elseif($tokens->is(Tokenizer::MACRO_SCALAR, '"')) {
                    $key = "[".$this->parseScalar($tokens, false)."]";
                } else {
                    break;
                }
                $_var .= $key;
            } elseif($t === "[" && !($options & self::DENY_ARRAY)) {
                $tokens->next();
                if($tokens->is(Tokenizer::MACRO_STRING)) {
                    if($tokens->isNext("(")) {
                        $key = "[".$this->parseExp($tokens)."]";
                    } else {
                        $key = '["'.$tokens->current().'"]';
                        $tokens->next();
                    }
                } else {
                    $key = "[".$this->parseExp($tokens, true)."]";
                }
                $tokens->get("]");
                $tokens->next();
                $_var .= $key;
            } elseif($t === T_DNUMBER) {
                $_var .= '['.substr($tokens->getAndNext(), 1).']';
            } else {
                break;
            }
        }
        return $_var;
    }

    /**
     * Parse variable
     * $var.foo[bar]["a"][1+3/$var]|mod:3:"w":$var3|mod3
     *
     * @see parseModifier
     * @static
     * @param Tokenizer $tokens
     * @param int                $deny set limitations
     * @param bool               $pure_var will be FALSE if variable modified
     * @throws \LogicException
     * @throws UnexpectedTokenException
     * @return string
     */
    public function parseVariable(Tokenizer $tokens, $deny = 0, &$pure_var = true) {
        $_var = $this->parseVar($tokens, $deny);
        $pure_var = true;
        while($t = $tokens->key()) {
            if($t === "|" && !($deny & self::DENY_MODS)) {
                $pure_var = false;
                return $this->parseModifier($tokens, $_var);
            } elseif($t === T_OBJECT_OPERATOR) {
                $prop = $tokens->getNext(T_STRING);
                if($tokens->isNext("(")) {
                    if($this->_options & Fenom::DENY_METHODS) {
                        throw new \LogicException("Forbidden to call methods");
                    }
                    $pure_var = false;
                    $tokens->next();
                    $_var .= '->'.$prop.$this->parseArgs($tokens);
                } else {
                    $tokens->next();
                    $_var .= '->'.$prop;
                }
            } elseif($t === "?" || $t === "!") {
                $pure_var = false;
                return $this->parseTernary($tokens, $_var, $t);
            } else {
                break;
            }
        }
        return $_var;
    }

    /**
     * Parse ternary operator
     *
     * @param Tokenizer $tokens
     * @param $var
     * @param $type
     * @return string
     * @throws UnexpectedTokenException
     */
    public function parseTernary(Tokenizer $tokens, $var, $type) {
        $empty = ($type === "?");
        $tokens->next();
        if($tokens->is(":")) {
            $tokens->next();
            if($empty) {
                return '(empty('.$var.') ? ('.$this->parseExp($tokens, true).') : '.$var.')';
            } else {
                return '(isset('.$var.') ? '.$var.' : ('.$this->parseExp($tokens, true).'))';
            }
        } elseif($tokens->is(Tokenizer::MACRO_BINARY, Tokenizer::MACRO_BOOLEAN, Tokenizer::MACRO_MATH) || !$tokens->valid()) {
            if($empty) {
                return '!empty('.$var.')';
            } else {
                return 'isset('.$var.')';
            }
        } else {
            $expr1 = $this->parseExp($tokens, true);
            if(!$tokens->is(":")) {
                throw new UnexpectedTokenException($tokens, null, "ternary operator");
            }
            $expr2 = $this->parseExp($tokens, true);
            if($empty) {
                return '(empty('.$var.') ? '.$expr2.' : '.$expr1.')';
            } else {
                return '(isset('.$var.') ? '.$expr1.' : '.$expr2.')';
            }
        }
    }

    /**
     * Parse scalar values
     *
     * @param Tokenizer $tokens
     * @param bool $allow_mods
     * @return string
     * @throws TokenizeException
     */
    public function parseScalar(Tokenizer $tokens, $allow_mods = true) {
        $_scalar = "";
        if($token = $tokens->key()) {
            switch($token) {
                case T_CONSTANT_ENCAPSED_STRING:
                case T_LNUMBER:
                case T_DNUMBER:
                    $_scalar .= $tokens->getAndNext();
                    break;
                case T_ENCAPSED_AND_WHITESPACE:
                case '"':
                    $_scalar .= $this->parseSubstr($tokens);
                    break;
                default:
                    throw new TokenizeException("Unexpected scalar token '".$tokens->current()."'");
            }
            if($allow_mods && $tokens->is("|")) {
                return $this->parseModifier($tokens, $_scalar);
            }
        }
        return $_scalar;
    }

    /**
     * Parse string with or without variable
     *
     * @param Tokenizer $tokens
     * @throws UnexpectedTokenException
     * @return string
     */
    public function parseSubstr(Tokenizer $tokens) {
        if($tokens->is('"',"`")) {
            $stop = $tokens->current();
            $_str = '"';
            $tokens->next();
            while($t = $tokens->key()) {
                if($t === T_ENCAPSED_AND_WHITESPACE) {
                    $_str .= $tokens->current();
                    $tokens->next();
                } elseif($t === T_VARIABLE) {
                    if(strlen($_str) > 1) {
                        $_str .= '".';
                    } else {
                        $_str = "";
                    }
                    $_str .= '$tpl["'.substr($tokens->current(), 1).'"]';
                    $tokens->next();
                    if($tokens->is($stop)) {
                        $tokens->skip();
                        return $_str;
                    } else {
                        $_str .= '."';
                    }
                } elseif($t === T_CURLY_OPEN) {
                    if(strlen($_str) > 1) {
                        $_str .= '".';
                    } else {
                        $_str = "";
                    }
                    $tokens->getNext(T_VARIABLE);
                    $_str .= '('.$this->parseExp($tokens).')';
                    if($tokens->is($stop)) {
                        $tokens->next();
                        return $_str;
                    } else {
                        $_str .= '."';
                    }
                } elseif($t === "}") {
                    $tokens->next();
                } elseif($t === $stop) {
                    $tokens->next();
                    return $_str.'"';
                } else {

                    break;
                }
            }
            throw new UnexpectedTokenException($tokens);
        } elseif($tokens->is(T_CONSTANT_ENCAPSED_STRING)) {
            return $tokens->getAndNext();
        } elseif($tokens->is(T_ENCAPSED_AND_WHITESPACE)) {
            throw new UnexpectedTokenException($tokens);
        } else {
            return "";
        }
    }

    /**
     * Parse modifiers
     * |modifier:1:2.3:'string':false:$var:(4+5*$var3)|modifier2:"str {$var+3} ing":$arr.item
     *
     * @param Tokenizer $tokens
     * @param                    $value
     * @throws \LogicException
     * @throws \Exception
     * @return string
     */
    public function parseModifier(Tokenizer $tokens, $value) {
        while($tokens->is("|")) {
            $mods = $this->_fenom->getModifier( $modifier_name = $tokens->getNext(Tokenizer::MACRO_STRING) );

            $tokens->next();
            $args = array();

            while($tokens->is(":")) {
                $token = $tokens->getNext(Tokenizer::MACRO_SCALAR, T_VARIABLE, '"', Tokenizer::MACRO_STRING, "(", "[");

                if($tokens->is(Tokenizer::MACRO_SCALAR) || $tokens->isSpecialVal()) {
                    $args[] = $token;
                    $tokens->next();
                } elseif($tokens->is(T_VARIABLE)) {
                    $args[] = $this->parseVariable($tokens, self::DENY_MODS);
                } elseif($tokens->is('"', '`', T_ENCAPSED_AND_WHITESPACE)) {
                    $args[] = $this->parseSubstr($tokens);
                } elseif($tokens->is('(')) {
                    $args[] = $this->parseExp($tokens);
                } elseif($tokens->is('[')) {
                    $args[] = $this->parseArray($tokens);
                } elseif($tokens->is(T_STRING) && $tokens->isNext('('))  {
                    $args[] = $tokens->getAndNext().$this->parseArgs($tokens);
                } else {
                    break;
                }
            }


            if(!is_string($mods)) { // dynamic modifier
                $mods = 'call_user_func($tpl->getStorage()->getModifier("'.$modifier_name.'"), ';
            } else {
                $mods .= "(";
            }
            if($args) {
                $value = $mods.$value.', '.implode(", ", $args).')';
            } else {
                $value = $mods.$value.')';
            }
        }
        return $value;
    }

    /**
     * Parse array
     * [1, 2.3, 5+7/$var, 'string', "str {$var+3} ing", $var2, []]
     *
     * @param Tokenizer $tokens
     * @throws UnexpectedTokenException
     * @return string
     */
    public function parseArray(Tokenizer $tokens) {
        if($tokens->is("[")) {
            $_arr = "array(";
            $key = $val = false;
            $tokens->next();
            while($tokens->valid()) {
                if($tokens->is(',') && $val) {
                    $key = true;
                    $val = false;
                    $_arr .= $tokens->getAndNext().' ';
                } elseif($tokens->is(Tokenizer::MACRO_SCALAR, T_VARIABLE, T_STRING, T_EMPTY, T_ISSET, "(", "#") && !$val) {
                    $_arr .= $this->parseExp($tokens, true);
                    $key = false;
                    $val = true;
                } elseif($tokens->is('"') && !$val) {
                    $_arr .= $this->parseSubstr($tokens);
                    $key = false;
                    $val = true;
                } elseif($tokens->is(T_DOUBLE_ARROW) && $val) {
                    $_arr .= ' '.$tokens->getAndNext().' ';
                    $key = true;
                    $val = false;
                } elseif(!$val && $tokens->is('[')) {
                    $_arr .= $this->parseArray($tokens);
                    $key = false;
                    $val = true;
                } elseif($tokens->is(']') && !$key) {
                    $tokens->next();
                    return $_arr.')';
                } else {
                    break;
                }
            }
        }
        throw new UnexpectedTokenException($tokens);
    }

    /**
     * Parse constant
     * #Ns\MyClass::CONST1, #CONST1, #MyClass::CONST1
     *
     * @param Tokenizer $tokens
     * @return string
     * @throws InvalidUsageException
     */
    public function parseConst(Tokenizer $tokens) {
        $tokens->get('#');
        $name = $tokens->getNext(T_STRING);
        $tokens->next();
        if($tokens->is(T_NAMESPACE)) {
            $name .= '\\';
            $name .= $tokens->getNext(T_STRING);
            $tokens->next();
        }
        if($tokens->is(T_DOUBLE_COLON)) {
            $name .= '::';
            $name .= $tokens->getNext(T_STRING);
            $tokens->next();
        }
        if(defined($name)) {
            return $name;
        } else {
            throw new InvalidUsageException("Use undefined constant $name");
        }
    }

    /**
     * @param Tokenizer $tokens
     * @param $name
     * @return string
     * @throws InvalidUsageException
     */
    public function parseMacro(Tokenizer $tokens, $name) {
        if(isset($this->macros[ $name ])) {
            $macro = $this->macros[ $name ];
            $p = $this->parseParams($tokens);
            $args = array();
            foreach($macro["args"] as $arg) {
                if(isset($p[ $arg ])) {
                    $args[ $arg ] = $p[ $arg ];
                } elseif(isset($macro["defaults"][ $arg ])) {
                    $args[ $arg ] = $macro["defaults"][ $arg ];
                } else {
                    throw new InvalidUsageException("Macro '$name' require '$arg' argument");
                }
            }
            $args = $args ? '$tpl = '.Compiler::toArray($args).';' : '';
            return '$_tpl = $tpl; '.$args.' ?>'.$macro["body"].'<?php $tpl = $_tpl; unset($_tpl);';
        } else {
            throw new InvalidUsageException("Undefined macro '$name'");
        }
    }

    /**
     * Parse argument list
     * (1 + 2.3, 'string', $var, [2,4])
     *
     * @static
     * @param Tokenizer $tokens
     * @throws TokenizeException
     * @return string
     */
    public function parseArgs(Tokenizer $tokens) {
        $_args = "(";
        $tokens->next();
        $arg = $colon = false;
        while($tokens->valid()) {
            if(!$arg && $tokens->is(T_VARIABLE, T_STRING, "(", Tokenizer::MACRO_SCALAR, '"', Tokenizer::MACRO_UNARY, Tokenizer::MACRO_INCDEC)) {
                $_args .= $this->parseExp($tokens, true);
                $arg = true;
                $colon = false;
            } elseif(!$arg && $tokens->is('[')) {
                $_args .= $this->parseArray($tokens);
                $arg = true;
                $colon = false;
            } elseif($arg && $tokens->is(',')) {
                $_args .= $tokens->getAndNext().' ';
                $arg = false;
                $colon = true;
            } elseif(!$colon && $tokens->is(')')) {
                $tokens->next();
                return $_args.')';
            } else {
                break;
            }
        }

        throw new TokenizeException("Unexpected token '".$tokens->current()."' in argument list");
    }

    /**
     * Parse first unnamed argument
     *
     * @param Tokenizer $tokens
     * @param string $static
     * @return mixed|string
     */
    public function parsePlainArg(Tokenizer $tokens, &$static) {
        if($tokens->is(T_CONSTANT_ENCAPSED_STRING)) {
            if($tokens->isNext('|')) {
                return $this->parseExp($tokens, true);
            } else {
                $str = $tokens->getAndNext();
                $static = stripslashes(substr($str, 1, -1));
                return $str;
            }
        } elseif($tokens->is(Tokenizer::MACRO_STRING)) {
            $static = $tokens->getAndNext();
            return '"'.addslashes($static).'"';
        } else {
            return $this->parseExp($tokens, true);
        }
    }

    /**
     * Parse parameters as $key=$value
     * param1=$var param2=3 ...
     *
     * @static
     * @param Tokenizer $tokens
     * @param array     $defaults
     * @throws \Exception
     * @return array
     */
    public function parseParams(Tokenizer $tokens, array $defaults = null) {
        $params = array();
        while($tokens->valid()) {
            if($tokens->is(Tokenizer::MACRO_STRING)) {
                $key = $tokens->getAndNext();
                if($defaults && !isset($defaults[$key])) {
                    throw new \Exception("Unknown parameter '$key'");
                }
                if($tokens->is("=")) {
                    $tokens->next();
                    $params[ $key ] = $this->parseExp($tokens);
                } else {
                    $params[ $key ] = 'true';
                }
            } elseif($tokens->is(Tokenizer::MACRO_SCALAR, '"', '`', T_VARIABLE, "[", '(')) {
                $params[] = $this->parseExp($tokens);
            } else {
                break;
            }
        }
        if($defaults) {
            $params += $defaults;
        }

        return $params;
    }
}

class CompileException extends \ErrorException {}
class SecurityException extends CompileException {}
class InvalidUsageException extends \LogicException {}
class TokenizeException extends \RuntimeException {}