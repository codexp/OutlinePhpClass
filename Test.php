<?pHp namespace NS;

/**
 * doc block documentation!
 *
 * use this file for testing with the OutlinePhpClass extension.
 * this php code is not functional and may have syntactic errors
 * for testing purposes.
 */
class User { var $variable = 'content';
    public $name;

    /**
        is user an admin?

         if he is, he will have power!
        */
    private $isAdmin = true;

    protected static $test = array(
        'rights'        => true,
        'adminUserName' => 'root'
    );

    function __construct($name) {
        // code goes here
        $this->name = $name;
    }

    /** login
     */
    public function login($password) {
        /**
         * anonymous function
         */
        $isAdmin = function () {
            return false;
        };

        /**
         * nested function
         */
        function logout() {
            // do nothing here
        }

        // try to login bla bla
        if( self::auth($this->name, $password) ) {
            $res = true;
            $this->isAdmin = $isAdmin();
        } else {
            $res = false;
        }

        return $res;
    }

    /**
     * authentication
     */
    private static function _auth(&$user, $password = array(1,2,3)) {
        return false;
    }

    /**
     * get db
     */
    abstract protected static function _db($user, $password) {
        return $db;
    }
}