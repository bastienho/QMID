#!/usr/bin/php
<?php
/* 
 * QMID: Queru's mail IMAP dispatcher.
 * Jorge Fuertes AKA Queru - jorge@jorgefuertes.com
 * July 2009.
 * GPL3 Lisenced.
 * @author Jorge Fuertes
 * @version 1.0
 * @package clasifica-correo-imap
 *
 */

/*
 * Configuration:
 */
 define('APP_PATH', dirname(__FILE__));
 define('RULES', APP_PATH."/qmid-rules.conf");
 require(APP_PATH.DIRECTORY_SEPARATOR."qmid.conf");
 define('IMAP_CONN',  "{".IMAP_HOST.":".IMAP_PORT.IMAP_OPTIONS."}".IMAP_INBOX);
 error_reporting(ERRORLEVEL);

 /* Instantiate general output and error: */
 $output = new Output();
 $error  = new ErrorControl();

 $output->say("+++ QMID DISPATCHER +++", 2);
 $inicio = time();
 $output->say("> Started at: ".date(DATEFORMAT));

 /* New dispatcher wich parses the rules: */
 $dispatcher = new Dispatcher;

 /* Imap: */
 $imap = new imap();
 $output->say("> ".$imap->count()." messages to process.");
 $dispatcher->imap = $imap;

 /* Process the mail */
 $dispatcher->processMailbox();

 /* END */
 unset($imap);
 $error->Finish();

 /*
  * All imap work.
  */
 class imap
 {
     private $conn;
     private $output;
     private $error;

     function __construct()
     {
         $this->output = new Output();
         $this->error  = new ErrorControl();
         # Opens imap conection:
         $this->output->say("> Server: ".IMAP_CONN.".");
         $this->output->say("> Connecting to IMAP server...", 0);
         $this->conn = imap_open(IMAP_CONN, IMAP_USER, IMAP_PASS);
         if(!$this->conn)
         {
             $this->output->say("FAIL");
             $this->error->CriticalError("IMAP connection error.");
         }
         else
         {
             $this->output->say("OK");
         }

         ###DEBUG###
         #$mailboxes = imap_list($this->conn, "{".IMAP_HOST."}", "*");
         ###########
     }

     /*
      * Busca mensajes por su cabecera.
      * @param string $header Header to search.
      * @param string $text   Text that must be in the header.
      * @return array         Array of mensajes matching the search.
      */
     function SearchByHeader($header, $text)
     {
         # Prepare the admited headers regexp:
         $imap2_headers = "/^".str_replace(", ", "$|^", IMAP2_HEADERS)."$/i";
         # Check if the header it's an admited to do imap2_search:
         if(preg_match($imap2_headers, $header))
         {
             # It's an IMAP2 admited header. We can directly look for that.
             $query = $header.' "'.$text.'"';             
         }
         else
         {
             # HEADER not admited by IMAP2 search. Need to simulate.
             $this->output->say("Not admited...", 0);
             return false;
         }

         $this->output->say("(QUERY: ".$query.")...", 0);
         $aResults = imap_search($this->conn, $query, FT_UID);
         if(count($aResults) > 0)
         {
            return $aResults;
         }
         else
         {
            return false;
         }
     }

     /*
      * Get msg overview.
      * @param integer $uid Message UID.
      * @return array       Message overview.
      */
     function FetchOverview($uid)
     {
         $aOverviews = imap_fetch_overview($this->conn, $uid, FT_UID);
         if($aOverviews)
         {
             return $aOverviews[0];
         }
         else
         {
             $this->error->Warn("> Cannot fetch message uid ".$uid.".");
             return false;
         }
     }

     /*
      * Cuenta los mensajes en el inbox.
      * @return integer
      */
     function count()
     {
         return imap_num_msg($this->conn);
     }

     /*
      * Execute a rule over an array of message uids
      * @param array $rule     The rule.
      * @param array $aResults The messages result set.
      * @return boolean        True if everything it's ok.
      */
     function ExecuteAction($rule, $aResults)
     {
        $this->output->say("    - Executing action: " . $rule['action'], 0);
        if ($rule['destination'] !== false)
        {
            $this->output->say("-->".$rule['destination'].":");
        }
        else
        {
            $this->output->say(":");
        }

        foreach($aResults as $key => $uid)
        {
            $this->output->say("      - Message id ".$uid."...", 0);
            if($rule['action'] == "MOVE")
            {
                $success = imap_mail_move($this->conn, $uid, $rule['destination'], CP_UID);
            }
            elseif($rule['action'] == "COPY")
            {
                $success = imap_mail_copy($this->conn, $uid, $rule['destination'], CP_UID);
            }
            elseif($rule['action'] == "DELETE")
            {
                $success = imap_delete($this->conn, $uid, FT_UID);
            }

            if($success)
            {
                $this->output->say("OK");
            }
            else
            {
                $this->output->say("FAIL");
            }
        }
     }

     function __destruct()
     {
         $this->output->say("> Cleaning mailboxes...", 0);
         imap_expunge($this->conn);
         $this->output->say("OK");
         imap_close($this->conn);
         $this->output->say("> IMAP connection closed.");
     }
 }


 /*
  * Sort and dispatching class.
  *
  */
 class Dispatcher
 {
     private $aRules;
     private $error;
     private $output;
     public  $imap;

     function __construct()
     {
         $this->output       = new Output();
         $this->error        = new ErrorControl();
         $this->aRules = $this->loadRules();
     }

     /*
      * Procesado de mensajes.
      */
     function processMailbox()
     {
         if(empty($this->imap))
         {
             $this->error->CriticalError("Missing IMAP connection.");
         }
         else
         {
             $this->output->say("> Executing rules.");
             foreach($this->aRules as $key => $rule)
             {
                $this->output->say("  + Executing rule ".$key."/".count($this->aRules).": ".$rule['name']."...", 0);
                if($rule['type'] == "HEAD")
                {
                    $aResults = $this->imap->SearchByHeader($rule['header'], $rule['text']);
                }
                else
                {
                    $aResults = false;
                }
                if($aResults !== false)
                {
                    $this->output->say(count($aResults)." matches:");
                    if(count($aResults) > 0)
                    {
                        $this->output->say("    - Matches: ", 0);
                        foreach($aResults as $num => $uid)
                        {
                            $this->output->say("[".$uid."] ", 0);
                        }
                        $this->output->say("");
                    }

                    # Executing the rule's action:
                    $this->imap->ExecuteAction($rule, $aResults);
                }
                else
                {
                    $this->output->say("no matches.");
                }
             }
        }
     }

     /*
      * Load, parse and returns the rules.
      */
     function loadRules()
     {
         $this->output->say("> Processing rules.");
	 if(file_exists(RULES))
	 {
		$aRules = array();
		$fRules = @fopen(RULES, "r");
		while (!feof($fRules))
		{
                        $row = trim(fgets($fRules, 4096));
                        if(!preg_match("/^\#|^$/", $row))
                        {
                            if(preg_match("/^.*\|.*\|.*\|.*\$/", $row))
                            {
                                list($name, $type, $text, $action) = explode("|", $row);
                                $name        = trim($name);
                                $type        = trim($type);
                                $text        = trim($text);
                                $action      = trim($action);
                                if(!preg_match("/^(HEAD\:.+|TEXT)$/i", $type)
                                        or !preg_match("/^(MOVE\:.+|COPY\:.+|DELETE)$/i", $action))
                                {
                                    $this->error->Warn("Unknown rule type: '".$row."'");
                                }
                                else
                                {
                                    if(preg_match("/HEAD/i", $type))
                                    {
                                        # It's a header. Catch it.
                                        list($type_only, $header) = explode(":", $type);
                                    }
                                    else
                                    {
                                        $type_only = $type;
                                        $header = false;
                                    }

                                    if(!preg_match("/MOVE|COPY/i", $type))
                                    {
                                        list($action_only, $destination) = explode(":", $action);
                                    }
                                    else
                                    {
                                        $action_only = $action;
                                        $destination = false;
                                    }

                                    $aRules[] = array(
                                                'name'        => $name,
                                                'type'        => $type_only,
                                                'header'      => strtoupper($header),
                                                'text'        => $text,
                                                'action'      => strtoupper($action_only),
                                                'destination' => $destination);
                                }
                            }
                            else
                            {
                                $this->error->Warn("rule: '".$row.".");
                            }
                        }
	        }
        	fclose($fRules);
                $this->output->say("> Process finished, ".count($aRules)." rules loaded.");
	        return $aRules;
	 }
	 else
	 {
                $this->error->CriticalError("No existe el fichero de configuración.");
		return false;
	 }
    }

    /*
     * Returns rules array.
     */
    public function getRules()
    {
        return $this->aRules;
    }
    
    /*
     * Shows a human readable list of rules:
     */
    public function showRules()
    {
        foreach($this->aRules as $key => $rule)
        {
            $this->output->say(" - [".$key.":".$rule['nombre']."]-->".$rule['destino']);
        }
    }

 }

 /*
  * Clase que se ocupa de la salida por terminal o log.
  */
 class Output
 {
    var $verbose;

    /*
     * Verbose only if we are in a tty.
     */
    function __construct()
    {
        if(posix_isatty(STDOUT))
        {
            $this->verbose = true;
        }
        else
        {
            $this->verbose = false;
        }
    }

    /*
     * Terminal and log output.
     */
    public function say($txt, $nLF = 1, $error = false, $log = true)
    {
        # Carrige return's string:
        $rtns = "";
        while($nLF > 0)
        {
            $rtns .= "\n";
            $nLF--;
        }
        # Echo in verbose mode only:
        if($this->verbose or $error)
        {
            echo $txt.$rtns;
        }

        # Log:
        if($log)
        {
            error_log($txt.$rtns, 3, LOGFILE);
        }
    }
 }

 /*
  * Error control class.
  */
 class ErrorControl
 {
    private $ErrorCount = 0;
    private $output;

    function __construct()
    {
        $this->output = new Output();
    }

    public function Warn($txt)
    {
        $this->ErrorCount++;
        $this->output->say("*** ERROR: ".$txt." ***", 2, true);
    }

    public function CriticalError($txt)
    {
        $this->ErrorCount++;
        $this->output->say("*** ERROR: ".$txt." ***", 2, true);
        $this->Finish();
    }

    public function Finish()
    {
        if($this->ErrorCount == 0)
        {
            $this->output->say("> Ended without errors.", 2);
            $level = 0;
        }
        else
        {
            $this->output->say("> Ended with ".$this->ErrorCount." errors.", 2, true);
            $level = 1;
        }

        exit($level);
    }
 }

 ?>
