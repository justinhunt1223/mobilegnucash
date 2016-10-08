<?php

header('Content-Type: text/plain; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

class Index {

    // *** User variables ***
    private $sUsername = '';
    private $sPassword = '';
    private $sDatabase = '';
    private $sDatabaseServer = '';
    // ***

    public $sVersion = '2.1.0';

    public $cGnuCash;
    public $aReturn = array('return' => 0, 'message' => '', 'error_code' => 0);
    public $aData;

    public $aAccountTypes = array('INCOME', 'EXPENSE', 'BANK', 'ASSET', 'EQUITY', 'CREDIT', 'LIABILITY', 'RECEIVABLE', 'CASH');

    public function __construct() {
        if (!isset($_POST['data'])) {
            $this->done();
        }
        $sData = base64_decode($_POST['data']);
        $this->aData = json_decode($sData, true);

        if (isset($this->aData['test_connection']) and $this->aData) {
            $this->aReturn['return'] = 1;
            $this->aReturn['hardcoded_credentials'] = (($this->sUsername and $this->sPassword) ? 1 : 0);
            $this->aReturn['username'] = $this->sUsername;
            $this->aReturn['password'] = $this->sPassword ? "yes" : "no";
            $this->aReturn['database_server'] = $this->sDatabaseServer;
            $this->aReturn['database'] = $this->sDatabase;
            $this->done();
        }

        if (!$this->sUsername AND !empty($this->aData['login']['username'])) {
            $this->sUsername = $this->aData['login']['username'];
        }

        if (!$this->sPassword AND !empty($this->aData['login']['password'])) {
            $this->sPassword = $this->aData['login']['password'];
        }

        if (!$this->sDatabase AND !empty($this->aData['login']['database'])) {
            $this->sDatabase = $this->aData['login']['database'];
        }

        if (!$this->sDatabaseServer) {
            if (!empty($this->aData['login']['database_server'])) {
                $this->sDatabaseServer = $this->aData['login']['database_server'];
            } else {
                $this->sDatabaseServer = '127.0.0.1';
            }
        }

        $this->cGnuCash = new GnuCash($this->sDatabaseServer, $this->sDatabase, $this->sUsername, $this->sPassword);

        if ($this->cGnuCash->getErrorCode()) {
            $this->aReturn['message'] = "Database connection failed.<br /><b>{$this->cGnuCash->getErrorMessage()}</b>";
            $this->aReturn['error_code'] = $this->cGnuCash->getErrorCode();
            $this->done();
        } else if (!$this->cGnuCash->getAccounts() or isset($this->aData['test_credentials'])) {
            if (isset($this->aData['test_credentials'])) {
                $this->aReturn['return'] = 1;
                $this->aReturn['databases'] = $this->cGnuCash->getDatabases();
                $this->aReturn['database'] = $this->sDatabase;
                $this->done();
            }
            if (!$this->sDatabase) {
                $this->aReturn['message'] = 'No database specified.';
            } else {
                $this->aReturn['message'] = "No accounts found, double check the database: {$this->sDatabase}";
            }
            $this->done();
        }

        $sFunction = $this->aData['func'];
        if (method_exists($this, $sFunction)) {
            $this->$sFunction();
        }
        $this->done();
    }

    private function done($sMessage = null) {
        if ($sMessage) {
            $this->aReturn['message'] = $sMessage;
        }
        exit(json_encode($this->aReturn));
    }

    private function checkDatabaseLock() {
        $aLock = $this->cGnuCash->isLocked();
        if ($aLock) {
            $this->aReturn['message'] = "GnuCash database is locked by: {$aLock['Hostname']}";
            $this->done();
        }
    }

    private function appCheckSettings() {
        $this->aReturn['return'] = 1;
        $this->aReturn['version'] = $this->sVersion;
        $this->aReturn['message'] = 'Settings verified.';
    }

    private function appFetchAccounts() {
        $this->aReturn['return']  = 1;
        $this->aReturn['accounts'] = array();

        $aAccounts = $this->cGnuCash->getAccounts();
        foreach ($aAccounts as $aAccount) {
            $sPrefix = $aAccount['account_type'] . ': ';
            if (strpos($sPrefix, 'INCOME') !== false) {
                $sPrefix = 'Income: ';
            } else if (strpos($sPrefix, 'EXPENSE') !== false) {
                $sPrefix = 'Expenses: ';
            } else if (strpos($sPrefix, 'BANK') !== false) {
                $sPrefix = 'Bank: ';
            } else if (strpos($sPrefix, 'ROOT') !== false) {
                $sPrefix = 'Root: ';
            } else if (strpos($sPrefix, 'PAYABLE') !== false) {
                $sPrefix = 'A/P: ';
            } else if (strpos($sPrefix, 'RECEIVABLE') !== false) {
                $sPrefix = 'A/R: ';
            } else if (strpos($sPrefix, 'CREDIT') !== false) {
                $sPrefix = 'Card: ';
            } else if (strpos($sPrefix, 'ASSET') !== false) {
                $sPrefix = 'Asset: ';
            } else if (strpos($sPrefix, 'EQUITY') !== false) {
                $sPrefix = 'Equity: ';
            } else if (strpos($sPrefix, 'LIABILITY') !== false) {
                $sPrefix = 'Liability: ';
            } else if (strpos($sPrefix, 'CASH') !== false) {
                $sPrefix = 'Cash: ';
            }
            $this->aReturn['accounts'][] = array(
                'name' => "$sPrefix{$aAccount['name']}",
                'simple_name' => $aAccount['name'],
                'count' => $aAccount['Count'],
                'guid' => $aAccount['guid'],
                'is_parent' => (count($this->cGnuCash->getChildAccounts($aAccount['guid'])) > 0) * 1
            );
        }
    }

    private function appGetAccountDescriptions() {
        $sAccountGUID = $this->aData['account_guid'];
        $aTransactions = $this->cGnuCash->getAccountTransactions($sAccountGUID);

        $this->aReturn['return'] = 1;
        $this->aReturn['descriptions'] = array();
        $aDescriptions = array();

        foreach ($aTransactions as $aTransaction) {
            $aTransactionInfo = $this->cGnuCash->getTransactionInfo($aTransaction['tx_guid']);
            foreach ($aTransactionInfo[1] as $aTransactionSplit) {
                if (!in_array($aTransaction['description'], $aDescriptions) and $aTransactionSplit['account_guid'] != $aTransaction['account_guid']) {
                    $aDescriptions[] = $aTransaction['description'];
                    $aTransferToAccount = $this->cGnuCash->getAccountInfo($aTransactionSplit['account_guid']);
                    $this->aReturn['descriptions'][] = array(
                        'title' => $aTransaction['description'],
                        'description' => $aTransferToAccount['name'],
                        'guid' => $aTransactionSplit['account_guid']
                    );
                }
            }
        }
    }

    private function appCreateTransaction() {
        $this->checkDatabaseLock();
        $sDebitGUID = $this->aData['debit_guid'];
        $sCreditGUID = $this->aData['credit_guid'];
        $fAmount = $this->aData['amount'];
        $sDescription = $this->aData['description'];
        $sDate = $this->aData['date'];
        if (!$sDate) {
            $sDate = date('Y-m-d H:i:s', time());
        } else {
            $sDate = date('Y-m-d H:i:s', strtotime($sDate));
        }
        $sMemo = $this->aData['memo'];
        if (!$sMemo) { $sMemo = ''; }

        if (!$this->cGnuCash->GUIDExists($sDebitGUID)) {
            $this->aReturn['message'] = "GUID: $sDebitGUID does not exist for to account.";
        } else if (!$this->cGnuCash->GUIDExists($sCreditGUID)) {
            $this->aReturn['message'] = "GUID: $sCreditGUID does not exist for from account.";
        } else if (!is_numeric($fAmount)) {
            $this->aReturn['message'] = "$fAmount is not a valid number.";
        } else if (empty($sDescription)) {
            $this->aReturn['message'] = 'Please enter a name for this transaction.';
        } else if (empty($sDate) or !(bool)strtotime($sDate)) {
            $this->aReturn['message'] = 'Please enter a valid date for this transaction.';
        } else {
            $this->aReturn['message'] = $this->cGnuCash->createTransaction($sDebitGUID, $sCreditGUID, $fAmount, $sDescription, $sDate, $sMemo);
            if ($this->aReturn['message']) {
            } else {
                $this->aReturn['return'] = 1;
                $this->aReturn['message'] = 'Transaction successful.';
            }
        }
    }

    private function appDeleteTransaction() {
        $sTransactionGUID = $this->aData['guid'];

        if (!$this->cGnuCash->GUIDExists($sTransactionGUID)) {
            $this->aReturn['message'] = "GUID: $sTransactionGUID does not exist.";
        } else if (!$this->cGnuCash->deleteTransaction($sTransactionGUID)) {
            $this->aReturn['message'] = 'Failed to delete transaction.';
        } else {
            $this->aReturn['return'] = 1;
            $this->aReturn['message'] = 'Successfully deleted transaction.';
        }
    }

    private function appGetAccountTransactions() {
        $sAccountGUID = $this->aData['guid'];
        $this->aReturn['transactions'] = array();

        $aTransactions = $this->cGnuCash->getAccountTransactions($sAccountGUID);
        if ($aTransactions) {
            $this->aReturn['return'] = 1;
            foreach ($aTransactions as $aTransaction) {
                $aDate = explode(' ', $aTransaction['post_date']);
                $this->aReturn['transactions'][] =
                    array(
                        'guid' => $aTransaction['tx_guid'],
                        'description' => $aTransaction['description'],
                        'amount' => number_format(($aTransaction['value_num'] / $aTransaction['value_denom']), 2),
                        'memo' => $aTransaction['memo'],
                        'date' => date('m-d-y', strtotime($aDate[0])),
                        'reconciled' => $aTransaction['reconcile_state'] == 'c'
                    );
            }
        } else {
            $this->aReturn['message'] = 'No transactions for this account.';
        }
    }

    private function appUpdateTransactionReconciledStatus() {
        $sTransactionGUID = $this->aData['guid'];
        $bReconciled = filter_var($this->aData['reconciled'], FILTER_VALIDATE_BOOLEAN);

        $bSet = $this->cGnuCash->setReconciledStatus($sTransactionGUID, $bReconciled);

        $this->aReturn['reconciled'] = !$bReconciled * 1;

        if ($bSet) {
            $this->aReturn['return'] = 1;
        } else {
            $this->aReturn['message'] = 'Failed to update reconciled status of transaction.';
        }
    }

    private function appGetAccountHeirarchy() {
        $aAccounts = $this->cGnuCash->getAllAccounts();

        $aHeirarchy = array();
        function copyAccounts($cPage, $aAccount, &$aHeirarchyPointer, $aKeys) {
            if ($aAccount['name'] == 'Template Root') { return; }
            $aTransactions = $cPage->cGnuCash->getAccountTransactions($aAccount['guid']);
            $fTotal = 0;
            $bAllReconciled = true;
            foreach ($aTransactions as $aTransaction) {
                $fTotal += $aTransaction['value_num'] / $aTransaction['value_denom'];
                if ($aTransaction['reconcile_state'] != 'c') {
                    $bAllReconciled = false;
                }
            }
            $aNewAccount = array(
                'name' => $aAccount['name'],
                'guid' => $aAccount['guid'],
                'total' => $fTotal,
                'all_transactions_reconciled' => $bAllReconciled,
                'sub_accounts' => array()
            );

            $aTempHeirarchy = &$aHeirarchyPointer;
            foreach ($aKeys as $sKey) {
                $aTempHeirarchy = &$aTempHeirarchy[$sKey]['sub_accounts'];
            }
            $aChildAccounts = $cPage->cGnuCash->getChildAccounts($aAccount['guid']);
            if ($aChildAccounts) {
                if (!array_key_exists($aAccount['guid'], $aTempHeirarchy)) {
                    $aTempHeirarchy[$aAccount['guid']] = $aNewAccount;
                }
                $aKeys[] = $aAccount['guid'];
                foreach ($aChildAccounts as $aChildAccount) {
                    copyAccounts($cPage, $aChildAccount, $aHeirarchyPointer, $aKeys);
                }
            } else {
                if (!in_array($aAccount['guid'], $aTempHeirarchy)) {
                    $aTempHeirarchy[$aAccount['guid']] = $aNewAccount;
                }
            }
        }
        foreach ($aAccounts as $aAccount) {
            if (!$aAccount['parent_guid']) {
                copyAccounts($this, $aAccount, $aHeirarchy, array());
            }
        }
        $this->aReturn['accounts'] = $aHeirarchy;

    }

    public function appRenameAccount() {
        $this->aReturn['return'] = $this->cGnuCash->renameAccount($this->aData['guid'], $this->aData['new_account_name']) * 1;
    }

    public function appDeleteAccount() {
        $aReturn = $this->cGnuCash->deleteAccount($this->aData['guid']);
        $this->aReturn['return'] = $aReturn[0] * 1;
        $this->aReturn['message'] = $aReturn[1];
    }

    public function appCreateAccount() {
        $sName = $this->aData['name'];
        $sAccountType = $this->aData['account_type'];
        $sCommodityGUID = $this->aData['commodity_guid'];
        $sParentAccountGUID = $this->aData['parent_guid'];
        $this->aReturn['return'] = $this->cGnuCash->createAccount($sName, $sAccountType, $sCommodityGUID, $sParentAccountGUID);
    }

    public function appGetCreateAccountDialog() {
        $sAccountTypeDropdown = '<select class="ui dropdown" name="account_type">';
        foreach ($this->aAccountTypes as $sType) {
            $sAccountTypeDropdown .= '<option value="' . $sType . '">' . $sType . '</option>';
        }
        $sAccountTypeDropdown .= '</select>';

        $aCommodities = $this->cGnuCash->getCommodities();
        $sCommodityDropdown = '<select class="ui dropdown" name="commodity_guid">';
        foreach ($aCommodities as $aCommodity) {
            $sCommodityDropdown .= '<option value="' . $aCommodity['guid'] . '">' . $aCommodity['fullname'] . '</option>';
        }
        $sCommodityDropdown .= '</select>';
        $this->aReturn['form_id'] = 'fmNewAccount';
        $this->aReturn['html'] = '
        <form class="ui inverted form" id="' . $this->aReturn['form_id'] . '">
            <input type="hidden" name="parent_guid" val="" />
            <div class="field">
                <label>Name</label>
                <input name="name" type="text" />
            </div>
            <div class="field">
                <label>Type</label>
                ' . $sAccountTypeDropdown . '
            </div>
            <div class="field">
                <label>Currency</label>
                ' . $sCommodityDropdown . '
            </div>
        </form>
        ';
    }

    public function appChangeAccountParent() {
        $this->aReturn['return'] = $this->cGnuCash->changeAccountParent($this->aData['guid'], $this->aData['parent_guid']) * 1;
    }

    public function appChangeTransactionDescription() {
        $this->aReturn['return'] = $this->cGnuCash->changeTransactionDescription($this->aData['transaction_guid'], $this->aData['new_description']);
    }

    public function appChangeTransactionAmount() {
        $this->aReturn['return'] = $this->cGnuCash->changeTransactionAmount($this->aData['transaction_guid'], $this->aData['new_amount']);
    }

    public function appChangeTransactionDate() {
        $sDate = date('Y-m-d H:i:s', strtotime($this->aData['new_date']));
        $this->aReturn['return'] = $this->cGnuCash->changeTransactionDate($this->aData['transaction_guid'], $sDate);
    }
}

class GnuCash {

    private $con;
    private $eException;
    private $sDbName;
    private $aError;

    public function __construct($sHostname, $sDbName, $sUsername, $sPassword) {
        $this->sDbName = $sDbName;
        try {
            $this->con = new PDO("mysql:host=$sHostname;dbname=$sDbName", $sUsername, $sPassword, [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8']);
            $this->con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            $this->eException = $e;
        }
    }

    public function getErrorMessage() {
        if ($this->eException) {
            return $this->eException->getMessage();
        }
        return '';
    }

    public function getErrorCode() {
        if ($this->eException) {
            return $this->eException->getCode();
        }
        return 0;
    }

    public function runQuery($sSql, $aParameters = array(), $bReturnFirst = false) {
        try {
            $q = $this->con->prepare($sSql);
            $q->execute($aParameters);
            $q->setFetchMode(PDO::FETCH_ASSOC);
            $aReturn = array();
            while ($aRow = $q->fetch()) {
                if ($bReturnFirst) {
                    return $aRow;
                }
                $aReturn[] = $aRow;
            }
            return $aReturn;
        } catch(PDOException $e) {
            $this->eException = $e;
        }
    }

    public function getNewGUID() {
        mt_srand((double)microtime() * 10000);

        while (true) {
            $sTempGUID = strtolower(md5(uniqid(rand(), true)));
            //  Theoretically there is an extremely small chance that there are duplicates.
            //  However, why not?
            if (!$this->GUIDExists($sTempGUID)) {
                return $sTempGUID;
            }
        }
    }

    public function GUIDExists($sGUID) {
        $this->runQuery("USE `information_schema`;");
        $aTables = $this->runQuery("SELECT * FROM `TABLES` WHERE `TABLE_SCHEMA` LIKE :dbname;",
                                   array(':dbname' => $this->sDbName));
        $this->runQuery("USE `{$this->sDbName}`;");
        foreach ($aTables as $aTable) {
            $aGUIDs = $this->runQuery("SELECT * FROM `{$aTable['TABLE_NAME']}` WHERE `guid` LIKE :guid;",
                                      array(':guid' => $sGUID));
            if ($aGUIDs) {
                return true;
            }
        }
        return false;
    }

    public function getAccountInfo($sAccountGUID) {
        return $this->runQuery("SELECT * FROM `accounts` WHERE `guid` = :guid;",
                               array(':guid' => $sAccountGUID), true);
    }

    public function getAccounts() {
        return $this->runQuery("SELECT * FROM `accounts`
                                LEFT OUTER JOIN
                                    (
                                        SELECT `account_guid`, COUNT(`account_guid`) AS Count
                                        FROM `splits`
                                        GROUP BY `account_guid`
                                    ) counts
                                    ON `counts`.`account_guid` = `accounts`.`guid`
                                ORDER BY Count DESC;");
    }

    public function getAccountTransactions($sAccountGUID) {
        return $this->runQuery("SELECT `description`,
                                       `post_date`,
                                       `tx_guid`,
                                       `memo`,
                                       `reconcile_state`,
                                       `value_num`,
                                       `value_denom`,
                                       `quantity_num`,
                                       `quantity_denom`,
                                       `account_guid`
                                FROM `splits` LEFT JOIN `transactions` ON `transactions`.`guid` = `splits`.`tx_guid`
                                WHERE `account_guid` = :guid
                                ORDER BY `transactions`.`post_date` DESC;",
                                array(':guid' => $sAccountGUID));
    }

    public function getTransactionInfo($sGUID) {
        $aSplits = $this->runQuery("SELECT * FROM `splits` WHERE `tx_guid` = :guid;",
                                   array(':guid' => $sGUID));
        return array($this->getTransaction($sGUID), $aSplits);

    }

    public function getTransaction($sGUID) {
        return $this->runQuery("SELECT * FROM `transactions` WHERE `guid` = :guid;",
                               array(':guid' => $sGUID));
    }

    public function getSplit($sGUID) {
        return $this->runQuery("SELECT * FROM `splits` WHERE `guid` = :guid;",
                               array(':guid' => $sGUID), true);
    }

    public function isLocked() {
        // Bad juju to edit the database when it's locked.
        // I've done tests, and you can but the desktop client won't reflect changes that it didn't make.
        //  -So you can add a transaction while the desktop client is open but it won't show until you restart it.
        $aLocks = $this->runQuery("SELECT * FROM `gnclock`;");
        if ($aLocks) {
            return $aLocks[0];
        }
        return false;
    }

    public function createTransaction($sDebitGUID, $sCreditGUID, $fAmount, $sName, $sDate, $sMemo) {
        if ($this->isLocked()) {
            return 'Database is locked';
        }
        // Transaction GUID, same for both debit and credit entries in transactions.
        $sTransactionGUID = $this->getNewGUID();
        if (!$sTransactionGUID) {
            return 'Failed to get a new transaction GUID.';
        }
        $aAccount = $this->getAccountInfo($sDebitGUID);
        if (!$aAccount) {
            return 'Failed to retrieve account for GUID: ' . $sDebitGUID . '.';
        }
        $sCurrencyGUID = $aAccount['commodity_guid'];
        if (!$sCurrencyGUID) {
            return 'Currency GUID is empty.';
        }
        $sSplitDebitGUID = $this->getNewGUID();
        if (!$sSplitDebitGUID) {
            return 'Failed to get a new GUID for split 1.';
        }
        $sSplitCreditGUID = $this->getNewGUID();
        if (!$sSplitCreditGUID) {
            return 'Failed to get a new GUID for split 2.';
        }
        // Time may change during the execution of this function.
        $sEnterDate = date('Y-m-d H:i:s', time());

        $this->runQuery("INSERT INTO `transactions` (`guid`, `currency_guid`, `num`, `post_date`, `enter_date`, `description`) VALUES (:guid, :currency_guid, :num, :post_date, :enter_date, :description);",
                        array(':guid' => $sTransactionGUID, ':currency_guid' => $sCurrencyGUID, ':num' => '',
                              ':post_date' => $sDate, ':enter_date' => $sEnterDate, ':description' => $sName));
        $sTransactionMessage = $this->eException->getMessage();
        $aTransaction = $this->getTransaction($sTransactionGUID);
        $this->runQuery("INSERT INTO `splits` (`guid`, `tx_guid`, `account_guid`, `memo`, `action`, `reconcile_state`, `reconcile_date`, `value_num`, `value_denom`, `quantity_num`, `quantity_denom`) VALUES (:guid, :tx_guid, :account_guid, :memo, :action, :reconcile_state, :reconcile_date, :value_num, :value_denom, :quantity_num, :quantity_denom);",
                        array(':guid' => $sSplitDebitGUID, ':tx_guid' => $sTransactionGUID, ':account_guid' => $sDebitGUID,
                              ':memo' => $sMemo, ':reconcile_state' => 'n', ':reconcile_date' => null, ':action' => '',
                              ':value_num' => intval($fAmount * 100), ':value_denom' => 100,
                              ':quantity_num' => intval($fAmount * 100), ':quantity_denom' => 100));
        $sDebitMessage = $this->eException->getMessage();
        $aSplitDebit = $this->getSplit($sSplitDebitGUID);
        $this->runQuery("INSERT INTO `splits` (`guid`, `tx_guid`, `account_guid`, `memo`, `action`, `reconcile_state`, `reconcile_date`, `value_num`, `value_denom`, `quantity_num`, `quantity_denom`) VALUES (:guid, :tx_guid, :account_guid, :memo, :action, :reconcile_state, :reconcile_date, :value_num, :value_denom, :quantity_num, :quantity_denom);",
                        array(':guid' => $sSplitCreditGUID, ':tx_guid' => $sTransactionGUID, ':account_guid' => $sCreditGUID,
                              ':memo' => '', ':reconcile_state' => 'n', ':reconcile_date' => null, ':action' => '',
                              ':value_num' => -1 * intval($fAmount * 100), ':value_denom' => 100,
                              ':quantity_num' => -1 * intval($fAmount * 100), ':quantity_denom' => 100));
        $sCreditMessage = $this->eException->getMessage();
        $aSplitCredit = $this->getSplit($sSplitCreditGUID);

        if ($aTransaction and $aSplitDebit and $aSplitCredit) {
            return '';
        }
        // Something happened, delete what was entered.
        $this->deleteTransaction($sTransactionGUID);
        if (!$aTransaction or !$aSplitDebit or !$aSplitCredit) {
            $sError = 'Error:' . ($this->getErrorMessage() ? ' ' . $this->getErrorMessage() . '.' : '');
            if (!$aTransaction) {
                $sError .= ' Failed to create transaction record: <b>' . $sTransactionMessage . '</b>';
            }
            if (!$aSplitDebit) {
                $sError .= ' Failed to create debit split: <b>' . $sDebitMessage . '</b>';
            }
            if (!$aSplitCredit) {
                $sError .= ' Failed to create credit split: <b>' . $sCreditMessage . '</b>';
            }
            return $sError;
        }
        return 'Some other error.';
    }

    public function deleteTransaction($sTransactionGUID) {
        if ($this->isLocked()) {
            return false;
        }
        $this->runQuery("DELETE FROM `transactions` WHERE `guid` = :guid;",
                        array(':guid' => $sTransactionGUID));
        $this->runQuery("DELETE FROM `splits` WHERE `tx_guid` = :guid;",
                        array(':guid' => $sTransactionGUID));

        // Verify entries were deleted.
        $aTransaction = $this->getTransactionInfo($sTransactionGUID);
        if ($aTransaction[0] or $aTransaction[1]) {
            return false;
        }
        return true;
    }

    public function setReconciledStatus($sTransactionGUID, $bReconciled) {
        $sReconciled = ($bReconciled ? 'n' : 'c');
        $this->runQuery("UPDATE `splits` SET `reconcile_state` = :reconcile_state WHERE `tx_guid` = :tx_guid;",
                        array(':reconcile_state' => $sReconciled, ':tx_guid' => $sTransactionGUID));
        $aTransactions = $this->runQuery("SELECT * FROM `splits` WHERE `tx_guid` = :tx_guid;",
                                         array(':tx_guid' => $sTransactionGUID));
        $bSet = true;
        foreach ($aTransactions as $aTransaction) {
            if ($aTransaction['reconcile_state'] != $sReconciled) {
                $bSet = false;
            }
        }
        return $bSet;
    }

    public function getAllAccounts() {
        return $this->runQuery("SELECT * FROM `accounts` ORDER BY code, name");
    }

    public function getChildAccounts($sParentGUID) {
        return $this->runQuery("SELECT * FROM `accounts` WHERE `parent_guid` = :parent_guid ORDER BY code, name",
                               array(':parent_guid' => $sParentGUID));
    }

    public function renameAccount($sAccountGUID, $sNewAccountName) {
        $this->runQuery("UPDATE `accounts` SET `name` = :name WHERE `guid` = :guid;",
                        array(':name' => $sNewAccountName, ':guid' => $sAccountGUID));
        $aAccount = $this->runQuery("SELECT * FROM `accounts` WHERE `guid` = :guid;",
                                    array(':guid' => $sAccountGUID), true);
        return ($sNewAccountName == $aAccount['name']);
    }

    public function deleteAccount($sAccountGUID) {
        $aChildAccounts = $this->getChildAccounts($sAccountGUID);
        if ($aChildAccounts) {
            return array(0, 'Account has child accounts, can&rsquo;t delete.');
        }
        $aAccount = $this->getAccountInfo($sAccountGUID);
        if ($aAccount['account_type'] == 'ROOT') {
            return array(0, 'Can&rsquo;t delete the root account.');
        }
        $aTransactions = $this->getAccountTransactions($sAccountGUID);
        foreach ($aTransactions as $aTransaction) {
            $this->deleteTransaction($aTransaction['tx_guid']);
        }
        $this->runQuery("DELETE FROM `accounts` WHERE `guid` = :guid;",
                        array(':guid' => $sAccountGUID));
        return array(1, '');
        // TODO: Delete scheduled transactions and other entries that reference this account guid.
    }

    public function createAccount($sName, $sAccountType, $sCommodityGUID, $sParentAccountGUID) {
        $aAccountExists = $this->runQuery("SELECT * FROM `accounts` WHERE `parent_guid` = :parent_guid AND `account_name` = :account_name AND `type` = :type;",
                                          array(':parent_guid' => $sParentAccountGUID, ':name' => $sName, ':account_type' => $sAccountType));
        if ($aAccountExists) {
            return false;
        }
        $aCommodity = $this->runQuery("SELECT * FROM `commodities` WHERE `guid` = :guid;",
                                      array(':guid' => $sCommodityGUID), true);
        $sAccountGUID = $this->getNewGUID();
        $this->runQuery("
            INSERT INTO `accounts` (`guid`, `name`, `account_type`, `commodity_guid`, `commodity_scu`, `non_std_scu`, `parent_guid`, `hidden`, `placeholder`)
            VALUES (:guid, :name, :account_type, :commodity_guid, :commodity_scu, :non_std_scu, :parent_guid, :hidden, :placeholder);",
            array(':guid' => $sAccountGUID, ':name' => $sName, ':account_type' => $sAccountType, ':commodity_guid' => $aCommodity['guid'], ':commodity_scu' => $aCommodity['fraction'], ':non_std_scu' => 0, ':parent_guid' => $sParentAccountGUID, ':hidden' => 0, ':placeholder' => 0)
        );
        $aNewAccount = $this->getAccountInfo($sAccountGUID);
        return !empty($aNewAccount);
    }

    public function getCommodities() {
        return $this->runQuery("SELECT * FROM `commodities`;");
    }

    public function changeAccountParent($sAccountGUID, $sParentAccountGUID) {
        $this->runQuery("UPDATE `accounts` SET `parent_guid` = :parent_guid WHERE `guid` = :guid;",
                        array(':parent_guid' => $sParentAccountGUID, ':guid' => $sAccountGUID));
        $aAccount = $this->getAccountInfo($sAccountGUID);
        return ($aAccount['parent_guid'] == $sParentAccountGUID);
    }

    public function changeTransactionDescription($sTransactionGUID, $sNewDescription) {
        $this->runQuery("UPDATE `transactions` SET `description` = :description WHERE `guid` = :guid;",
                        array(':description' => $sNewDescription, ':guid' => $sTransactionGUID));
        $aTransactionInfo = $this->runQuery("SELECT * FROM `transactions` WHERE `guid` = :guid;",
                                            array(':guid' => $sTransactionGUID), true);
        return ($aTransactionInfo['description'] == $sNewDescription);
    }

    public function changeTransactionAmount($sTransactionGUID, $sNewAmount) {
        // TODO: How to calculate the value/quantity based on value/quantity denominators.
        $this->runQuery("UPDATE `splits` SET `value_num` = :value_num, `quantity_num` = :quantity_num WHERE `tx_guid` = :tx_guid AND `value_num` < 0;",
                        array(':value_num' => ($sNewAmount * -1) * 100, ':quantity_num' => ($sNewAmount * -1) * 100, ':tx_guid' => $sTransactionGUID));
        $this->runQuery("UPDATE `splits` SET `value_num` = :value_num, `quantity_num` = :quantity_num WHERE `tx_guid` = :tx_guid AND `value_num` > 0;",
                        array(':value_num' => $sNewAmount * 100, ':quantity_num' => $sNewAmount * 100, ':tx_guid' => $sTransactionGUID));
        $aTransactionInfo = $this->getTransactionInfo($sTransactionGUID);
        // TODO: Verify.
        return true;
    }

    public function changeTransactionDate($sTransactionGUID, $sNewDate) {
        $this->runQuery("UPDATE `transactions` SET `post_date` = :post_date, `enter_date` = :enter_date WHERE `guid` = :guid;",
                        array(':post_date' => $sNewDate, ':enter_date' => $sNewDate, ':guid' => $sTransactionGUID));
        $aTransaction = $this->runQuery("SELECT * FROM `transactions` WHERE `guid` = :guid;",
                                        array(':guid' => $sTransactionGUID), true);
        $oNewDate = new DateTime($sNewDate);
        $oPostDate = new DateTime($aTransaction['post_date']);
        $oEnterDate = new DateTime($aTransaction['enter_date']);
        return ($oNewDate == $oPostDate) and ($oNewDate == $oEnterDate);
    }

    public function getDatabases() {
        $aDatabases = $this->runQuery('SHOW DATABASES;');
        $aReturn = [];
        foreach ($aDatabases as $aDatabase) {
            if (in_array($aDatabase['Database'], ['information_schema', 'performance_schema', 'mysql'])) {
                continue;
            }
            $aReturn[] = $aDatabase['Database'];
        }
        return $aReturn;
    }
}

new Index();
