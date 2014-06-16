<?php
// direct call protection
if( !defined('MEDIAWIKI') ) exit();

# $opcWhitelist - must be set in LocalSettings
# $opcBlacklist - can be set in LocalSettings
# $opcMaxNestingLevel = 2000; // not sure if it's needed
$opcDebug           = false; // new opcDebugClass; // or false

if( $opcDebug ) {
    $opcDebug->log('debug started at ' . time());
}

/**
 * WikiMedia extension class
 *
 * includes php class definition as content
 *
 * TODO:
 *   - functions
 *   - constants (maybe root's define)
 *   - param types
 *   - class extends and implements
 *
 * @version 0.0.1-05.06.2014
 * @license GPL
 * @author  Eugen Wesseloh <codexp_at_gmx.net>
 *    05.06.2014 created
 *
 *    This is my first attempt to write an extension for MediaWiki,
 *    and this is my first attempt to write a parser for php code.
 *    So if there is anything that is not how it's done the right way,
 *    I would be glad if someone would make suggestions or contribute.
 */
class OutlinePhpClassExtension
{
    const NAME    = 'OutlinePhpClass';
    const WARNING = '{{warning|%s}}';

    /**
     * init extension
     *
     * @version 0.0.1-05.06.2014
     * @author  Eugen Wesseloh <codexp_at_gmx.net>
     */
    public static function initExtenstion()
    {
        global $wgExtensionCredits,
               $wgHooks;

        $wgExtensionCredits['parserhook'][] = array(
            'path'        => __DIR__,
            'name'        => self::NAME,
            'version'     => self::getVersion(),
            'description' => 'Includes php class definition as content',
            'author'      => 'Eugen Wesseloh',
            'url'         => 'https://github.com/codexp/OutlinePhpClass.git'
        );

        // parser setup callback
        $wgHooks['ParserFirstCallInit'][] = __CLASS__ . '::initParser';
        // add a hook to initialise the magic word
        $wgHooks['LanguageGetMagic'][]    = __CLASS__ . '::initMagic';
    }

    /**
     * init magic mords
     *
     * @version 0.0.1-05.06.2014
     * @author  Eugen Wesseloh <codexp_at_gmx.net>
     */
    public static function initMagic(&$magicWords, $langCode)
    {
        // Add the magic word
        // The first array element is whether to be case sensitive,
        // in this case (0) it is not case sensitive, 1 would be sensitive
        // All remaining elements are synonyms for our parser function
        $magicWords[self::NAME] = array(0, self::NAME, 'PhpClassOutline');

        // unless we return true, other parser functions extensions won't get loaded.
        return true;
    }

    /**
     * init parser
     *
     * @version 0.0.1-16.06.2014
     * @author  Eugen Wesseloh <codexp_at_gmx.net>
     */
    public static function initParser(&$parser)
    {
        global $opcMaxNestingLevel;

        // if( ini_get('xdebug.max_nesting_level') < $opcMaxNestingLevel ) {
        //     ini_set('xdebug.max_nesting_level', $opcMaxNestingLevel);
        // }

        # Set a function hook associating the magic word with our function
        $parser->setFunctionHook(self::NAME, __CLASS__ . '::render');

        return true;
    }

    /**
     * render entity
     *
     * poor parser inside, does as much as it needs, no syntax validation!
     *
     * @version 0.0.1-16.06.2014
     * @author  Eugen Wesseloh <codexp_at_gmx.net>
     */
    public static function render($parser, $file)
    {
        global $opcWhitelist,
               $opcBlacklist,
               $opcDebug;

        $isHTML  = false;
        $noparse = false;
        $output  = sprintf(self::WARNING, 'error');

        try {
        do {
            if( empty($opcWhitelist) || !is_array($opcWhitelist) ) {
                $output = sprintf(self::WARNING,
                    '$opcWhitelist is not set! check your LocalConfig!'
                );
                break;
            }

            $fi = pathinfo($file);

            if( 'php' !== $fi['extension'] ) {
                $output = sprintf(self::WARNING, 'invalid file type!');
                break;
            }

            // process whitelist
            $allowed = false;
            foreach( $opcWhitelist as $allowedPath ) {
                $_len = strlen($allowedPath);

                $_p = substr($file, 0, $_len);
                if( $_p === $allowedPath ) {
                    $allowed = true;
                    break;
                }
            }

            // process blacklist if set
            if( $allowed && !empty($opcBlacklist) ) {
                if( !is_array($opcBlacklist) ) {
                    $opcBlacklist = array($opcBlacklist);
                }

                foreach( $opcBlacklist as $notAllowedPath ) {
                    $_len = strlen($notAllowedPath);

                    $_p = substr($file, 0, $_len);
                    if( $_p === $notAllowedPath ) {
                        $allowed = false;
                        break;
                    }
                }
            }

            if( !$allowed ) {
                $output = sprintf(self::WARNING, 'access vialation!');
                break;
            }

            if( !is_file($file) ) {
                $output = sprintf(self::WARNING, 'file not found!');
                break;
            }

            $code   = file_get_contents($file);
            $tokens = token_get_all($code);
            $res    = array();
            $tmp    = array();
            $stack  = array();

            $t_mode = 'root';
            $t_init = false;

            /**
             * newClass function
             *
             * creates new class object and extracts information about it
             * from stack (reverse parsing)
             */
            $newClass = function () use (&$res, &$stack, &$makeDocComment)
            {
                $class = new opcTClass;

                $_s = $stack;   // dereference $stack
                array_pop($_s); // pop T_WHITESPACE
                array_pop($_s); // pop T_CLASS

                // walk back the stack and find information about class
                while( $t = array_pop($_s) ) {

                    switch( $t->code ) {
                    case T_CLASS: // ignore
                    case T_WHITESPACE: // ignore
                        break;
                    case T_FINAL:

                        $class->isFinal = true;

                        break;
                    case T_ABSTRACT:

                        $class->isAbstract = true;

                        break;
                    case T_DOC_COMMENT:

                        $class->doc = $makeDocComment($t, 0);

                        break 2;
                    default:
                        break 2;
                    }
                }

                $res['classes'][] = $class;


                return $class;
            };

            /**
             * updateProp function
             *
             * updates new class property with information about it
             * from stack (reverse parsing)
             */
            $updateProp = function ($prop) use (&$stack, &$makeDocComment)
            {
                $_s = $stack;   // dereference $stack
                array_pop($_s); // pop T_VARIABLE

                // walk back the stack and find information about class
                while( $t = array_pop($_s) ) {

                    switch( $t->code ) {
                    case T_VARIABLE: // ignore
                    case T_WHITESPACE: // ignore
                        break;
                    case T_VAR:
                        $prop->isVar = true;
                    case T_PUBLIC:

                        $prop->isPublic    = true;
                        $prop->isProtected = false;

                        break;
                    case T_PRIVATE:

                        $prop->isPublic    = false;
                        $prop->isProtected = false;

                        break;
                    case T_PROTECTED:

                        $prop->isPublic    = false;
                        $prop->isProtected = true;

                        break;
                    case T_STATIC:

                        $prop->isStatic = true;

                        break;
                    case T_DOC_COMMENT:

                        $prop->doc = $makeDocComment($t, 4);

                        break 2;
                    default:
                        break 2;
                    }
                }

                return $prop;
            };

            /**
             * updateMethod function
             *
             * updates new class method with information about it
             * from stack (reverse parsing)
             */
            $updateMethod = function ($method) use (&$stack, &$makeDocComment)
            {
                $_s = $stack;   // dereference $stack
                array_pop($_s); // pop T_STRING
                array_pop($_s); // pop T_WHITESPACE
                array_pop($_s); // pop T_FUNCTION

                // walk back the stack and find information about class
                while( $t = array_pop($_s) ) {

                    switch( $t->code ) {
                    case T_VARIABLE: // ignore
                    case T_WHITESPACE: // ignore
                        break;
                    case T_ABSTRACT:

                        $method->isAbstract = true;

                        break;
                    case T_PUBLIC:

                        $method->isPublic    = true;
                        $method->isProtected = false;

                        break;
                    case T_PRIVATE:

                        $method->isPublic    = false;
                        $method->isProtected = false;

                        break;
                    case T_PROTECTED:

                        $method->isPublic    = false;
                        $method->isProtected = true;

                        break;
                    case T_STATIC:

                        $method->isStatic = true;

                        break;
                    case T_DOC_COMMENT:

                        $method->doc = $makeDocComment($t, 4);

                        break 2;
                    default:
                        break 2;
                    }
                }

                return $method;
            };

            /**
             * setMode function
             *
             * sets new parser mode
             */
            $setMode = function ($mode = 'root') use (&$t_mode, &$t_init)
            {
                $t_init = ('root' !== $mode);
                $t_mode = $mode;
            };

            /**
             * makeDocComment function
             *
             * create a doc comment object and fix indentation
             * @param object $token  - doc comment token
             * @param mixed  $indent - reindent by value as follows:
             *                           false   - do nothing
             *                           true    - auto reindent based on second
             *                                     line indentation
             *                           numeric - reindent by this number of
             *                                     spaces (can be 0)
             */
            $makeDocComment = function ($token, $indent = false)
            {
                $doc = new StdClass;

                $lines = explode(PHP_EOL, $token->value);
                $lines_count = count($lines);

                if( (false !== $indent) && ($lines_count > 1) ) {
                    if( true === $indent ) {
                        $indent = strlen($lines[1]) - strlen(ltrim($lines[1])) - 1;
                    }
                    $indent = str_repeat(' ', max(0, $indent));

                    foreach( $lines as $i => &$line ) {
                        $line = trim($line);
                        if( $i ) {
                            if( ('' === $line) || ('*' !== $line[0]) ) {
                                $line = trim('* ' . $line);
                            }
                            $line = $indent . ' ' . $line;
                        } else {
                            $line = $indent . $line;
                        }
                    }
                }

                $doc->value   = implode(PHP_EOL, $lines);
                $doc->line    = $token->line;
                $doc->lines   = $lines_count;

                return $doc;
            };

            /*
             * main token processor loop
             */
            foreach( $tokens as &$token ) {
                // build token object
                $t = new StdClass;
                if( is_array($token) ) {
                    $t->code  = (int) $token[0];
                    $t->value = $token[1];
                    $t->line  = $token[2];
                    $t->name  = token_name($t->code);
                    $token[0] = $t->code . ' ' . $t->name;
                } else {
                    $t->code  = $token;
                    $t->value = $token;
                }
                $t->token = $token;

                // push token to stack
                $stack[] = $t;

                /*
                 * root mode
                 */
                if( 'root' === $t_mode ) {
                    switch( $t->code ) {
                    case T_CLASS:

                        $setMode('class');

                        continue 2;
                    case T_NAMESPACE:

                        $setMode('namespace');

                        continue 2;
                    }
                }

                /*
                 * mode processor
                 */
                switch( $t_mode ) {
                case 'class':

                    if( $t_init ) {
                        $t_init = false;
                        if( isset($class) ) {
                            throw new Exception("classes can not be nested!");
                        }
                        $class = $newClass();
                    }

                    switch( $t->code ) {
                    case T_WHITESPACE: // ignore
                        break;
                    case T_STRING:

                        $class->name = $t->value;

                        break;
                    case '{':

                        $setMode('class_root');

                        break;
                    }

                    break;
                case 'class_root':

                    switch( $t->code ) {
                    case T_WHITESPACE: // ignore
                        break;
                    case T_VARIABLE:

                        $classProp = $class->createProperty($t->value);
                        $updateProp($classProp);
                        $setMode('class_property');

                        break;
                    case T_FUNCTION:

                        $setMode('class_method');

                        break;
                    case '}':

                        unset($class);
                        $setMode();

                        break;
                    }

                    break;
                case 'class_property':

                    if( $t_init ) {
                        $t_init   = false;
                        $t_assign = false;
                        $t_expr   = '';
                    }

                    if( ';' === $t->code ) {

                        if( $t_assign ) {
                            $classProp->expr = trim($t_expr);
                        }

                        unset($classProp, $t_expr);
                        $setMode('class_root');

                        break;
                    }

                    if( $t_assign ) {
                        $t_expr .= $t->value;
                    } else {
                        switch( $t->code ) {
                        case T_WHITESPACE: // ignore
                            break;
                        case '=':

                            $t_assign = true;

                            break;
                        }
                    }

                    break;
                case 'class_method':

                    if( $t_init ) {
                        $t_init = false;
                        $method = null;
                    }

                    switch( $t->code ) {
                    case T_WHITESPACE: // ignore
                        break;
                    case T_STRING:

                        if( !isset($method) ) {
                            $method = $class->createMethod($t->value);
                            $updateMethod($method);
                        }

                        break;
                    case '(':

                        $setMode('class_method_params');

                        break;
                    case '{':

                        $setMode('class_method_root');

                        break;
                    }

                    break;
                case 'class_method_params':

                    if( $t_init ) {
                        $t_init      = false;
                        $t_assign    = false;
                        $t_expr      = '';
                        $t_byref     = false;
                        $t_blevel    = 0;
                        $methodParam = null;
                    }

                    if( $t_assign ) {
                        switch( $t->code ) {
                        case ',':

                            if( $t_blevel < 1 ) {
                                $t_assign = false;
                            }

                            break;
                        case '(':

                            ++$t_blevel;

                            break;
                        case ')':

                            if( $t_blevel > 0 ) {
                                --$t_blevel;
                            } else {
                                $t_assign = false;
                            }

                            break;
                        }

                        if( $t_assign ) {
                            $t_expr .= $t->value;
                            break;
                        }
                    }

                    switch( $t->code ) {
                    case T_WHITESPACE: // ignore
                        break;
                    case T_VARIABLE:

                        $methodParam = $method->createParam($t->value);

                        break;
                    case '&':

                        $t_byref = true;

                        break;
                    case '=':

                        $methodParam->isOptional = true;
                        $t_assign = true;

                        break;
                    case ')':

                        $setMode('class_method');

                    case ',':

                        if( isset($methodParam) ) {
                            if( $methodParam->isOptional ) {
                                $methodParam->expr = trim($t_expr);
                            }
                            if( $t_byref ) {
                                $methodParam->byRef = true;
                            }
                        } else {
                            if( (',' === $t->code) || $method->params ) {
                                $method->createParam('undefined');
                            }
                        }

                        $t_expr      = '';
                        $t_assign    = false;
                        $t_byref     = false;
                        $methodParam = null;

                        break;
                    }

                    break;
                case 'class_method_root':

                    if( $t_init ) {
                        $t_init  = false;
                        $t_level = 0;
                    }

                    switch( $t->code ) {
                    case T_WHITESPACE: // ignore
                        break;
                    case '{':

                        ++$t_level;

                        break;
                    case '}':

                        if( $t_level > 0 ) {
                            --$t_level;
                            break;
                        }

                        unset($method);
                        $setMode('class_root');

                        break;
                    }

                    break;
                case 'namespace':

                    if( isset($res['namespace']) ) {
                        throw new Exception("multiple namespace definitions");
                    }

                    switch( $t->code ) {
                    case ';':

                        $res['namespace'] = implode('', $tmp['ns']);
                        $setMode();

                        break;
                    case T_STRING:

                        $tmp['ns'][] = $t->value;

                        break;
                    case T_NS_SEPARATOR:

                        $tmp['ns'][] = '\\';

                        break;
                    }

                    break;
                }
            }

            $out = '';

            if( !empty($res['namespace']) ) {
                $out .= PHP_EOL;
                $out .= 'namespace ' . $res['namespace'] . ';' . PHP_EOL;
            }

            if( !empty($res['classes']) ) {
                foreach( $res['classes'] as $class ) {
                    $out .= PHP_EOL;
                    if( $class->doc ) {
                        $out .= $class->doc->value . PHP_EOL;
                    }
                    if( $class->isFinal ) {
                        $out .= 'final ';
                    }
                    if( $class->isAbstract ) {
                        $out .= 'abstract ';
                    }
                    $out .= 'class ' . $class->name;
                    $out .= PHP_EOL;
                    $out .= '{' . PHP_EOL;
                    if( !empty($class->properties) ) {
                        if( !empty($class->constants) ) {
                            $out .= PHP_EOL;
                        }
                        $indent = '    ';
                        $firstProp = true;
                        foreach( $class->properties as $prop ) {
                            if( !empty($prop->doc) ) {
                                if( !$firstProp ) {
                                    $out .= PHP_EOL;
                                }
                                $out .= $prop->doc->value . PHP_EOL;
                            }
                            $out .= $indent;
                            if( $prop->isVar ) {
                                $out .= 'var ';
                            }
                            elseif( $prop->isPublic ) {
                                $out .= 'public ';
                            }
                            elseif( $prop->isProtected ) {
                                $out .= 'protected ';
                            } else {
                                $out .= 'private ';
                            }
                            if( $prop->isStatic ) {
                                $out .= 'static ';
                            }
                            $out .= $prop->name;
                            if( isset($prop->expr) ) {
                                $out .= ' = ' . $prop->expr;
                            }
                            $out .= ';' . PHP_EOL;

                            $firstProp = false;
                        }
                    }
                    if( !empty($class->methods) ) {
                        $indent = '    ';
                        foreach( $class->methods as $method ) {
                            $out .= PHP_EOL;
                            if( !empty($method->doc) ) {
                                $out .= $method->doc->value . PHP_EOL;
                            }
                            $out .= $indent;
                            if( $method->isAbstract ) {
                                $out .= 'abstract ';
                            }
                            if( $method->isPublic ) {
                                $out .= 'public ';
                            }
                            elseif( $method->isProtected ) {
                                $out .= 'protected ';
                            } else {
                                $out .= 'private ';
                            }
                            if( $method->isStatic ) {
                                $out .= 'static ';
                            }
                            $out .= 'function ' . $method->name . '(';
                            if( !empty($method->params) ) {
                                $seperate = false;
                                foreach( $method->params as $param ) {
                                    if( $seperate ) {
                                        $out .= ', ';
                                    }
                                    if( $param->byRef ) {
                                        $out .= '&';
                                    }
                                    $out .= $param->name;
                                    if( $param->isOptional ) {
                                        $out .= ' = ' . $param->expr;
                                    }
                                    $seperate = true;
                                }
                            }
                            $out .= ') {...}' . PHP_EOL;
                        }
                    }
                    $out .= '}' . PHP_EOL;
                }
            }

            $output = '<syntaxhighlight><?php' . PHP_EOL
                        . $out . PHP_EOL . PHP_EOL
                        . ($opcDebug ? 'DEBUG:' . PHP_EOL . $opcDebug->flattenLog() : '') . PHP_EOL . PHP_EOL
                        // . '$tokens = ' . var_export($tokens, true) . PHP_EOL
                    . '</syntaxhighlight>';

        } while( false );
        } catch (Exception $e) {
            $output = sprintf(self::WARNING, 'parser error: ' . $e->getMessage());
        }

        return array($output,
            'noparse' => $noparse,
            'isHTML'  => $isHTML
        );
    }

    /**
     * get class version
     *
     * @version 0.0.1-05.06.2014
     * @author  Eugen Wesseloh <codexp_at_gmx.net>
     */
    public static function getVersion()
    {
        $r = new ReflectionClass(__CLASS__);

        $doc = $r->getDocComment();

        if( preg_match('/@version[\s]+(.+)/', $doc, $m) ) {
            $ver = explode('-', trim($m[1]), 2);
            $ver = $ver[0];
        } else {
            $ver = 'undefined';
        }

        return $ver;
    }
}

class opcTClass
{
    public $name;
    public $isAbstract = false;
    public $isFinal    = false;
    public $extends    = false;
    public $implements = false;
    public $doc        = false; // doc comment
    public $constants  = array();
    public $properties = array();
    public $methods    = array();

    public function createProperty($name)
    {
        if( $name instanceof opcTClassProperty ) {

            $name->class = $this;
            $this->properties[$name->name] = $name;

            return $name;
        }

        $prop = new opcTClassProperty($this, $name);

        $this->properties[$name] = $prop;

        return $prop;
    }

    public function createMethod($name)
    {
        if( $name instanceof opcTClassMethod ) {

            $name->class = $this;
            $this->methods[$name->name] = $name;

            return $name;
        }

        $method = new opcTClassMethod($this, $name);

        $this->methods[$name] = $method;

        return $method;
    }
}

class opcTClassProperty
{
    public $name;
    public $isVar    = false; // defined via var
    public $isPublic = true;
    public $isStatic = false;
    public $doc      = false; // doc comment
    public $expr;             // assigned expression
    public $class;

    public function __construct($class, $name)
    {
        $this->class = $class;
        $this->name  = $name;
    }
}

class opcTClassMethod
{
    public $name;
    public $isPublic   = true;
    public $isStatic   = false;
    public $isAbstract = false;
    public $doc        = false; // doc comment
    public $class;
    public $params     = array();

    public function __construct($class, $name)
    {
        $this->class = $class;
        $this->name  = $name;
    }

    public function createParam($name)
    {
        if( $name instanceof opcTMethodParam ) {

            $name->method = $this;
            $this->params[$name->name] = $name;

            return $name;
        }

        $param = new opcTMethodParam($this, $name);

        $this->params[$name] = $param;

        return $param;
    }
}

class opcTMethodParam
{
    public $name;
    public $byRef      = false;
    public $isOptional = false;
    public $expr;
    public $method;

    public function __construct($method, $name)
    {
        $this->method = $method;
        $this->name   = $name;
    }
}

class opcDebugClass {
    private $_log = array();

    public function log($msg) {
        $this->_log[] = $msg;
    }

    public function flattenLog() {
        return implode(PHP_EOL, $this->_log) . PHP_EOL;
    }
}

OutlinePhpClassExtension::initExtenstion();
