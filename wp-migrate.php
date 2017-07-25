<?php

    class WPMigrate {

        private $_allowedActions = array ('check_wp_config', 'fix_database', 'fix_domain', 'complete');
        private $_defaultAction = 'check_wp_config';
        private $_actionNumber = 0;

        private $_view = false;
        private $_viewData = array();
        private $_messages = array();

        private $_readyForNext = false;
        private $_requiredTables = array('options', 'posts', 'postmeta');

        public function run() {

            $action = $this->_readAction();
            switch($action) {
                case 'check_wp_config':
                default:
                    $result = $this->_checkWPConfig();
                    break;

                case 'fix_database':
                    $result = $this->_fixDatabase();
                    break;

                case 'fix_domain':
                    $result = $this->_fixDomain();
                    break;

                case 'complete':
                    $result = $this->_complete();
                    break;
            }

            $this->_readyForNext = $result;
            return $result;
        }

        public function isReadyForNext() {
            return $this->_readyForNext;
        }

        public function getMessages() {
            $result = '';
            if (count($this->_messages) > 0) {
                $result .= '<ul id="messages" class="list-unstyled">';
                foreach ($this->_messages as $m) {
                    $result .= "\n<li class='" . $m['class'] . "'>" . $m['text'] . '</li>';
                }
                $result .= '</ul>';
            }

            return $result;
        }

        public function getView() {
            return $this->_view;
        }

        public function getViewData() {
            return $this->_viewData;
        }

        public function stepCompleted($actionNumber) {
            return $this->_actionNumber + 1 > $actionNumber;
        }

        public function getStepNumber() {
            return $this->_actionNumber + 1;
        }

        public function getAction() {
            return $this->_readAction();
        }

        private function _addMessage($text, $isError=false) {
            $this->_messages[] = array (
                'text' => $text,
                'class' => ($isError ? 'error' : 'success')
            );
        }

        private function _checkWPConfig() {
            $result = true;
            $wpConfigPath = $this->_getWPConfigPath();

            // 1. Check if wp-config.php exists and is readable
            if (file_exists($wpConfigPath) && is_readable($wpConfigPath)) {
                $this->_addMessage('<code>wp-config.php</code> has been located and is readable');

            } else {
                $this->_addMessage(
                    '<code>wp-config.php</code> cannot be found or is unreadable. Looked at [' . $wpConfigPath . ']',
                    true
                );

                $result = false;
            }

            if ($result) {

                // 2. Check if the preg patterns match
                $parseResult = $this->_parseWPConfigFile($wpConfigPath);
                if (in_array(false, $parseResult, true)) {

                    foreach ($parseResult as $propertyName => $value) {
                        if ($value === false) $this->_addMessage(
                            'There was an error parsing <code>wp-config.php</code>: ' .
                            'could not determine the value of <code>' . $propertyName . '</code>. Check the config' .
                            'file for problems.'
                            ,
                            true
                        );
                    }
                    $result = false;

                } else {
                    $this->_addMessage(
                        'WPMigrate can parse <code>wp-config.php</code> and determine your database account info.'
                    );
                }

                // 3. Check if wp-config.php is writable
                if (!is_writable($wpConfigPath)) {
                    $this->_addMessage(
                        '<code>wp-config.php</code> is not writable. Change the permissions of [' .
                        realpath($wpConfigPath) . ']',
                        true
                    );
                    $result = false;

                } else {
                    $this->_addMessage(
                        '<code>wp-config.php</code> is writable so WPMigrate can update your database config.'
                    );
                }
            }

            return $result;
        }

        private function _buildPDOConnectionString($config) {
            $dbName = $config['DB_NAME']['value'];
            $host = $config['DB_HOST']['value'];
            return 'mysql:host=' . $host . ';dbname=' . $dbName;
        }

        private function _connectToDatabase($parsedConfig) {
            $dbUser = $parsedConfig['DB_USER']['value'];
            $dbPass = $parsedConfig['DB_PASSWORD']['value'];
            $dbh = new PDO($this->_buildPDOConnectionString($parsedConfig), $dbUser, $dbPass);

            $this->_addMessage(
                "Connected successfully to database '" . $parsedConfig['DB_NAME']['value'] . "' at '" .
                $parsedConfig['DB_HOST']['value'] . "'."
            );

            return $dbh;
        }

        private function _getDatabaseTableNames(PDO $dbh) {
            return $dbh->query("SHOW TABLES");
        }

        private function _fixDatabase() {

            if (array_key_exists('db_info', $_POST)) {
                $this->_updateWPConfigFile($_POST['db_info']);
            }

            $parsedConfig = $this->_parseWPConfigFile($this->_getWPConfigPath());
            $result = true;

            if (in_array(false, $parsedConfig, false)) {
                $this->_addMessage("Missing config values.");
                $result = false;
            }

            $dbh = false;
            if ($result) {
                try {
                    $dbh = $this->_connectToDatabase($parsedConfig);

                } catch (PDOException $e) {
                    $this->_addMessage("Could not connect to the configured database: " . $e->getMessage(), true);
                    $result = false;
                }
            }

            if ($dbh !== false) {
                try {
                    $tablesResult = $this->_getDatabaseTableNames($dbh);
                    $requiredTables = $this->_getRequiredTablesMap($parsedConfig['table_prefix']['value']);

                    foreach ($tablesResult as $tableRow) {
                        if (array_key_exists($tableRow[0], $requiredTables)) {
                            unset($requiredTables[$tableRow[0]]);
                        }
                    }

                    if (count($requiredTables) > 0) {
                        $this->_addMessage(
                            "One or more required tables could not be found in the database: <code>" .
                            implode('</code>, <code>', array_keys($requiredTables)) . "</code>. " .
                            "Either something is wrong with the database content, or the table prefix " .
                            "needs to be corrected."
                            ,
                            true
                        );
                        $result = false;

                    } else {
                        $this->_addMessage("All required tables are present in the database.");
                    }

                } catch (PDOException $e) {
                    $this->_addMessage("Could not determine the selected database's tables: " . $e->getMessage(), true);
                    $result = false;
                }
            }

            $this->_view = 'db_account_form';
            $this->_viewData = array(
                'contentUrlGuess' => $this->_buildNewHomeURL() . '/wp_content',
                'contentDirGuess' => dirname(__FILE__) . DIRECTORY_SEPARATOR . 'wp_content',
                'config' => $parsedConfig,
                'success' => $result
            );

            return $result;
        }

        private function _updateWPConfigFile(array $dbInfo) {
            $wpConfigPath = $this->_getWPConfigPath();
            $parsedConfig = $this->_parseWPConfigFile($wpConfigPath);

            $search = array();
            $replace = array();
            foreach ($parsedConfig as $key => $info) {

                if (array_key_exists($key, $dbInfo) && !empty($dbInfo[$key])) {
                    $search[] = $info['replace'];

                    if ($key == 'table_prefix') {
                        $replace[] = '$table_prefix = \'' . addslashes(trim($dbInfo[$key])) . '\'';

                    } else {
                        $replace[] = 'define(\'' . $key . '\', \'' . addslashes(trim($dbInfo[$key])) . '\')';
                    }
                }
            }

            $wpConfigFileContent = file_get_contents($wpConfigPath);
            $wpConfigNewFileContent = str_replace($search, $replace, $wpConfigFileContent);
            file_put_contents($wpConfigPath, $wpConfigNewFileContent);

            $this->_addMessage(
                "<code>wp-config.php</code> was successfully updated with the provided database account info"
            );
        }

        private function _getRequiredTablesMap($prefix) {
            $orig = $this->_requiredTables;
            $result = array();
            foreach ($orig as $table) {
                $result[$prefix . $table] = true;
            }

            return $result;
        }

        private function _loadWPConfigPatterns() {
            $patterns = array(
                'WP_CONTENT_DIR' => $this->_buildDefinePattern('WP_CONTENT_DIR', '[^\']*'),
                'WP_CONTENT_URL' => $this->_buildDefinePattern('WP_CONTENT_URL', '[^\']*'),
                'DB_NAME' => $this->_buildDefinePattern('DB_NAME', '%?[a-zA-Z_][0-9a-zA-Z_\\-]*%?'),
                'DB_USER' => $this->_buildDefinePattern('DB_USER', '%?[a-zA-Z_][0-9a-zA-Z_\\-]*%?'),
                'DB_PASSWORD' => $this->_buildDefinePattern('DB_PASSWORD', '[^\']*'),
                'DB_HOST' => $this->_buildDefinePattern('DB_HOST', '[^\']*'),
                'table_prefix' => '/\\$table_prefix\\s*=\\s*\'(' . '%?[a-zA-Z_][0-9a-zA-Z_\\-]*%?' . ')\'/'
            );

            return $patterns;
        }

        private function _buildDefinePattern($constantName, $valuesSubPattern) {
            return
                '/define\\s*\\(\\s*\'' .
                $constantName .
                '\'\\s*,\\s*\'(' .
                $valuesSubPattern .
                ')\'\\s*\\)/i'
                ;
        }

        private function _parseWPConfigFile($filePath) {
            $content = file_get_contents($filePath);
            $patterns = $this->_loadWPConfigPatterns();

            $result = array();
            foreach ($patterns as $name => $pattern) {
                $m = null;
                if (preg_match($pattern, $content, $m)) {
                    $result[$name] = array('value' => $m[1], 'replace' => $m[0]);
                } else {
                    $result[$name] = false;
                }
            }

            return $result;
        }

        private function _getWPConfigPath() {
            return dirname(__FILE__) . '/wp-config.php';
        }

        private function _fixDomain() {
            $result = true;

            $parsedConfig = $this->_parseWPConfigFile($this->_getWPConfigPath());
            $tablePrefix = $parsedConfig['table_prefix']['value'];
            $dbh = $this->_connectToDatabase($parsedConfig);

            $oldHomeURL = $this->_getCurrentHomeURL($dbh, $tablePrefix);
            $newHomeURL = $this->_buildNewHomeURL();

            $queries = $this->_buildSubstitutionQueries($oldHomeURL, $newHomeURL, $tablePrefix);
            if ($oldHomeURL != $newHomeURL && array_key_exists('run', $_GET) && $_GET['run'] === '1') {
                if ($this->_runQueries($dbh, $queries)) {
                    $oldHomeURL = $newHomeURL;
                }
            }

            if ($oldHomeURL != $newHomeURL) {
                $this->_addMessage(
                    "The currently configured home url does not match the calculated new home url. Execute the " .
                    "database queries below to fix this.",
                    true
                );

                $result = false;

            } else {
                $this->_addMessage(
                    "Currently configured home URL matches the expected value ({$newHomeURL})."
                );
            }

            $this->_viewData = array(
                'old_url' => $oldHomeURL,
                'new_url' => $newHomeURL,
                'queries' => $queries
            );

            $this->_view = 'home_url_form';
            return $result;
        }

        private function _runQueries(PDO $dbh, array $queries) {
            $success = true;
            foreach ($queries as $q) {

                try {
                    $affectedRows = $dbh->exec($q);
                    $this->_addMessage("Successfully executed query [$q]. {$affectedRows} row(s) was/were affected.");

                } catch (PDOException $e) {
                    $this->_addMessage(
                        "An error occurred while running the following query: [$q]. " . $e->getMessage(),
                        true
                    );

                    $success = false;
                }
            }

            return $success;
        }

        private function _buildSubstitutionQueries($oldHomeURL, $newHomeURL, $tablePrefix) {
            $oldHomeURL = addslashes($oldHomeURL);
            $newHomeURL = addslashes($newHomeURL);

            $result = array(
                "UPDATE {$tablePrefix}options SET option_value = replace(option_value, '{$oldHomeURL}', '{$newHomeURL}') WHERE option_name = 'home' OR option_name = 'siteurl'",
                "UPDATE {$tablePrefix}posts SET guid = REPLACE (guid, '{$oldHomeURL}', '{$newHomeURL}')",
                "UPDATE {$tablePrefix}posts SET post_content = REPLACE (post_content, '{$oldHomeURL}', '{$newHomeURL}')",
                "UPDATE {$tablePrefix}postmeta SET meta_value = REPLACE (meta_value, '{$oldHomeURL}', '{$newHomeURL}')"
            );

            return $result;
        }

        private function _buildNewHomeURL() {
            $result = 'http://' . $_SERVER['HTTP_HOST'];
            $fullPath = $_SERVER['SCRIPT_NAME'];

            $phpFile = '/wp-migrate.php';
            $fileNamePos = strpos($fullPath, $phpFile);
            if ($fileNamePos !== false) {
                $directory = substr($fullPath, 0, $fileNamePos);
            } else {
                $directory = '';
            }

            $result .= $directory;
            return $result;
        }

        private function _getCurrentHomeURL(PDO $dbh, $tablePrefix) {
            $return = '';
            $q = "SELECT option_value FROM `{$tablePrefix}options` WHERE option_name='siteurl'";

            if ($result = $dbh->query($q)) {
                $result = $result->fetch();
                $return = $result['option_value'];
            }

            if (strlen($return) < 1) {
                $this->_addMessage(
                    "Could not determine the currently configured 'siteurl' option. The following query was used: [" .
                    $q . "]", true
                );
            }

            return $return;
        }

        private function _complete() {
            $this->_viewData = $this->_buildNewHomeURL();
            return false;
        }

        private function _readAction() {
            $action = null;

            if (array_key_exists('action', $_REQUEST)) {
                $action = strtolower($_REQUEST['action']);
            }

            if (!in_array($action, $this->_allowedActions)) {
                $action = $this->_defaultAction;
            }

            for($i=0; $i<count($this->_allowedActions); $i++) if (strtolower($this->_allowedActions[$i]) == $action) {
                $this->_actionNumber = $i;
                break;
            }

            return $action;
        }
    }

    $wpMigrate = new WPMigrate();
    $wpMigrate->run();

?><!DOCTYPE html>
<html lang="en">
    <head>
        <!-- Meta, title, CSS, favicons, etc. -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="description" content="">
        <meta name="author" content="">

        <title>WordPress Server Migration Tool</title>

        <!-- Bootstrap core CSS -->
        <link href="./wp-migrate/css/bootstrap.css" rel="stylesheet">
        <link href="./wp-migrate/css/bootstrap-glyphicons.css" rel="stylesheet">
        <link href="./wp-migrate/css/wp-migrate.css" rel="stylesheet">

    </head>
    <body>

        <h1>WordPress Migration Tool</h1>

        <hr/>

        <p>
            After your WordPress site has been moved to another server, domain, or directory, this migration tool helps
            you fixing your database in three simple steps:
        </p>

        <ol class="list-unstyled">

            <li class="glyphicon <?php echo $wpMigrate->stepCompleted(1) ? 'glyphicon-ok' : 'glyphicon-unchecked' ?>">
                Check your wp-config file.
            </li>

            <li class="glyphicon <?php echo $wpMigrate->stepCompleted(2) ? 'glyphicon-ok' : 'glyphicon-unchecked' ?>">
                Enter your new database account details.
            </li>

            <li class="glyphicon <?php echo $wpMigrate->stepCompleted(3) ? 'glyphicon-ok' : 'glyphicon-unchecked' ?>">
                Replace the old site url by the new in the database.
            </li>

        </ol>

        <hr/>

        <?php if ($wpMigrate->getAction() != 'complete'): ?>
            <h2>Step <?php echo $wpMigrate->getStepNumber() ?>:
                <?php switch($wpMigrate->getAction()): case 'check_wp_config': ?>
                    Checking wp-config file
                <?php break; case 'fix_database': ?>
                    New database account
                <?php break; case 'fix_domain': ?>
                    Replace old site url
                <?php endswitch; ?>
            </h2>

            <?php echo $wpMigrate->getMessages(); ?>

            <hr/>


            <?php
            // (deliberate assignation as if condition)
            if ($view = $wpMigrate->getView()): $data = $wpMigrate->getViewData(); switch($view): case 'db_account_form':
                ?>

                <?php if ($data['success']): ?>
                    <p>
                        WPMigrate checked your database account info and detected no problems. If you nevertheless want
                        to change the account info, you can do so using the form below. Go to the next step when the
                        correct database account has been configured.
                    </p>

                <?php else: ?>
                    <p>
                        WPMigrate detected one or more problems with the database account currently configured in
                        <code>wp-config.php</code>. You can update your database account info using the form below.
                    </p>
                    <p>
                        Please be aware that WPMigrate cannot automatically create a new database or restore a backup;
                        these actions need to be performed manually before you can proceed to the next step.
                    </p>

                <?php endif; ?>

                <form id="db-account-info" method="post">
                    <input type="hidden" name="action" value="fix_database"/>
                    <fieldset>
                        <legend>Database Account Info</legend>

                        <div class="form-group">
                            <label for="db_host">Host</label>

                            <input
                                type="text" class="form-control" id="db_host" name="db_info[DB_HOST]"
                                placeholder="Enter host name"
                                value="<?php echo htmlspecialchars($data['config']['DB_HOST']['value']) ?>"
                                >

                        </div>

                        <div class="form-group">
                            <label for="db_name">Database Name</label>

                            <input
                                type="text" class="form-control" id="db_name" name="db_info[DB_NAME]"
                                placeholder="Enter database name"
                                value="<?php echo htmlspecialchars($data['config']['DB_NAME']['value']) ?>"
                                >

                        </div>

                        <div class="form-group">
                            <label for="db_user">Username</label>

                            <input
                                type="text" class="form-control" id="db_user" name="db_info[DB_USER]"
                                placeholder="Enter username"
                                value="<?php echo htmlspecialchars($data['config']['DB_USER']['value']) ?>"
                                >

                        </div>

                        <div class="form-group">
                            <label for="db_password">Password</label>

                            <input
                                type="password" class="form-control" id="db_password" name="db_info[DB_PASSWORD]"
                                placeholder="Enter a new password or leave empty to preserve the current one"
                                >

                        </div>

                        <div class="form-group">
                            <label for="db_table_prefix">Table Prefix</label>

                            <input
                                type="text" class="form-control" id="db_table_prefix" name="db_info[table_prefix]"
                                value="<?php echo htmlspecialchars($data['config']['table_prefix']['value']) ?>"
                                >

                        </div>

                        <legend style="margin-top: 20px;">Content Directory</legend>

                        <div class="form-group">
                            <label for="db_host">Content directory</label>

                            <input
                                type="text" class="form-control" id="db_host" name="db_info[WP_CONTENT_DIR]"
                                placeholder="Enter the absolute directory path of the content directory"
                                value="<?php echo htmlspecialchars($data['config']['WP_CONTENT_DIR']['value']) ?>"
                                >

                            <p style="font-size: small; margin: 4px 0;">Guessed value: <span style="font-family: monospace, sans-serif; background-color: #474949; color: white; padding: 2px;"><?php echo $data['contentDirGuess'] ?></span></p>

                        </div>

                        <div class="form-group">
                            <label for="db_host">Content URL</label>

                            <input
                                type="text" class="form-control" id="db_host" name="db_info[WP_CONTENT_URL]"
                                placeholder="Enter the public url of the content directory"
                                value="<?php echo htmlspecialchars($data['config']['WP_CONTENT_URL']['value']) ?>"
                                >

                            <p style="font-size: small; margin: 4px 0;">Guessed value: <span style="font-family: monospace, sans-serif; background-color: #474949; color: white; padding: 2px;"><?php echo $data['contentUrlGuess'] ?></span></p>

                        </div>

                        <hr/>

                        <button type="submit" class="btn btn-default">Submit</button>

                    </fieldset>
                </form>

            <?php break; case 'home_url_form': ?>

                <?php if ($data['old_url'] != $data['new_url']): ?>
                    <?php foreach ($data['queries'] as $q): ?>
                        <pre><?php echo $q ?></pre>
                    <?php endforeach; ?>

                    <p>
                        <a href="?action=fix_domain&run=1" class="btn btn-default">Run Queries</a>
                    </p>
                <?php endif; ?>

            <?php break; endswitch; endif; ?>

            <?php if ($wpMigrate->isReadyForNext()): ?>
            <div>
                <?php switch($wpMigrate->getAction()): case 'check_wp_config': ?>
                    <p class="text-center"><a href="?action=fix_database">Go to the next step &raquo;</a></p>
                <?php break; case 'fix_database': ?>
                    <p class="text-center"><a href="?action=fix_domain">Go to the next step &raquo;</a></p>
                <?php break; case 'fix_domain': ?>
                    <p class="text-center"><a href="?action=complete">Go to the next step &raquo;</a></p>
                <?php endswitch; ?>
            </div>
            <?php else: ?>
            <p>Please fix the problems mentioned above before continuing to the next step.</p>
            <?php endif; ?>

        <?php else: ?>
            <p class="lead text-center">Database migration completed!</p>
            <p class="text-center">
                Your WordPress database is successfully patched for
                <a href="<?php echo $wpMigrate->getViewData() ?>"><?php echo $wpMigrate->getViewData() ?></a>.
            </p>
            <p class="text-center">
                <em>Attention!</em><br/> Remember to rebuild your WP permalink structure using <br/>the WordPress admin. This
                will update the .htaccess file as well.
            </p>
            <p class="text-center"><a href="<?php echo $wpMigrate->getViewData() ?>">Go to the public site &raquo;</a></p>
        <?php endif; ?>

        <hr/>

        <script src="http://code.jquery.com/jquery-1.10.1.min.js"></script>
        <script src="./wp-migrate/js/bootstrap.js"></script>
    </body>
</html>