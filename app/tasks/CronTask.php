<?php
use Aiden\Models\Councils;
use Aiden\Models\DasDocuments;
use Aiden\Models\DasUsers;
use Phalcon\Mvc\Model\Resultset\Simple as Resultset;
use Mailgun\Mailgun;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

class CronTask extends \Phalcon\Cli\Task {

    /**
     * Finds a council that is due to be scraped, and scrapes it.
     * @return void
     */
    public function scrapeAction() {

        // Initialize all councils (in case they don't exist)
        $excludedFiles = [
            "_BaseTask.php",
            "CronTask.php",
            "DevTask.php",
            ".",
            ".."
        ];
        $taskFiles = array_diff(scandir(__DIR__), $excludedFiles);
        foreach ($taskFiles as $taskFile) {

            $taskName = explode(".", $taskFile)[0];
            //if($taskName == 'MarrickvilleTask'){
                $task = new $taskName();
                $task->initialize();
                $this->logger->info('-------------INITIALIZE '.$taskName.'-----------------');
           // }
        }

        $file = fopen("CRONLOCK", "w");
        if (flock($file, LOCK_EX | LOCK_NB)) {

            // orignal
            $sql = "SELECT * FROM `councils`"
                    . " WHERE `id` != 17 AND `id` != 2 AND `id` != 8 AND TIMESTAMPDIFF(SECOND, `councils`.`last_scrape`, NOW()) > " . $this->config->scrapeInterval
                    . " OR `last_scrape` IS NULL"
                    . " ORDER BY RAND()"
                    . " LIMIT 1";

//            $sql = "SELECT * FROM `councils`"
//                    . " WHERE `id` = 33
//                     ORDER BY RAND()"
//                    . " LIMIT 1";

            $councilModel = new Councils();
            $council = new Resultset(null, $councilModel, $councilModel->getReadConnection()->query($sql));

            if (count($council) > 0) {

                $relatedTaskName = str_replace([" ", "-"], "", $council[0]->getName());
                $relatedTaskName = strtolower($relatedTaskName);
                $relatedTaskName = ucfirst($relatedTaskName);
                $relatedTaskName = $relatedTaskName . "Task";

                $this->logger->info("Attempting to call {council} scrape method...", ["council" => $council[0]->getName()]);

                $relatedTask = new $relatedTaskName();
                $relatedTask->initialize();

                if ($council[0]->getCommand() === null || strlen($council[0]->getCommand()) === 0) {
                    $this->logger->info("Using default command \"{command}\"", ["command" => $relatedTask->council_default_param]);
                    $relatedTask->scrapeAction([$relatedTask->council_default_param]);
                }
                else {
                    $this->logger->info("Using command \"{command}\"", ["command" => $council[0]->getCommand()]);
                    $relatedTask->scrapeAction([$council[0]->getCommand()]);
                }
            }

            // Release lock
            $this->logger->info("Releasing lock");
            $this->logger->info("");
            flock($file, LOCK_UN);
        }
        else {
            $this->logger->info("Cron file locked, the crawler is already processing a council");
        }

        fclose($file);

        $this->scrapeAction();

    }

    /**
     * Downloads documents from council websites, and then uploads it to Amazon S3.
     * @return void
     */
    public function as3DocumentsAction() {

        $dasDocuments = DasDocuments::find("as3_processed = 0");
        foreach ($dasDocuments as $dasDocument) {

            $this->logger->info("");
            $this->logger->info("Processing document for {council_name} with id {document_id}", [
                "council_name" => $dasDocument->getDa()->getCouncil()->getName(),
                "document_id" => $dasDocument->getId()
            ]);

            // First verify whether the remote file exists
            $ch = curl_init($dasDocument->getUrl());
            curl_setopt($ch, CURLOPT_NOBODY, true);

            $curlOutput = curl_exec($ch);
            if ($curlOutput === false) {
                $this->logger->error("  Could not verify remote file exists, skipping...");
                continue;
            }

            $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpStatus !== 200) {
                $this->logger->warning("  Remote document returned a {http_status} http status", [
                    "http_status" => $httpStatus
                ]);
                continue;
            }

            curl_close($ch);

            // The remote file is the document's file on a council's server
            $remoteFileHandle = fopen($dasDocument->getUrl(), "rb");
            if ($remoteFileHandle === false) {
                $this->logger->error("  Unable to create file handle for remote file, skipping...");
                continue;
            }

            // We use a temporary local file so we can later upload its contents to Amazon S3
            $localFileName = $dasDocument->getId() . ".pdf";
            $localFilePath = $this->config->directories->docsDir . $localFileName;
            $localFileHandle = fopen($localFilePath, "w");

            if ($localFileHandle === false) {
                $this->logger->error("  Unable to create file handle for local file, skipping...");
                continue;
            }

            $this->logger->info("  Writing bytes to temp local file...");

            // Read through the file (4096 bytes at a time), and write it to our temp local file
            while (!feof($remoteFileHandle)) {
                $data = fread($remoteFileHandle, 4096);
                fwrite($localFileHandle, $data, strlen($data));
            }

            $this->logger->info("  Finished writing bytes...");

            fclose($remoteFileHandle);
            fclose($localFileHandle);

            // Create Amazon credentials
            $credentials = new Aws\Credentials\Credentials(
                    $this->config->amazon->access_key, $this->config->amazon->secret_access_key
            );

            // Create Amazon S3 Client
            $client = new S3Client([
                "version" => "latest",
                "region" => $this->config->amazon->region,
                "credentials" => $credentials,
                "debug" => !$this->config->dev
            ]);

            try {

                $result = $client->putObject([
                    'Bucket' => $this->config->amazon->bucket,
                    'Key' => $fileName,
                    'Body' => file_get_contents($filePath),
                    'ACL' => 'public-read',
                    "Content-MD5" => base64_encode(md5_file($filePath)) // Throws an error if hashes don't match.
                ]);

                $dasDocument->setAs3Url($result["ObjectURL"]);
                $dasDocument->setAs3Processed(true);

                if ($dasDocument->save()) {
                    $this->logger->info("  Document was successfully saved to Amazon S3");
                }

                // Document couldn't be saved, something's wrong, delete the document from S3
                else {

                    $this->logger->error(print_r($dasDocument->getMessages(), true));

                    // Try to delete the object from Amazon S3
                    $client->deleteObject([
                        "Bucket" => $this->config->amazon->bucket,
                        "Key" => $fileName
                    ]);
                }

                // In any case, delete the local file (on failure it'll be re-created).
                unlink($filePath);
            }
            catch (S3Exception $e) {
                $this->logger->error($e->getMessage());
            }
        }

    }

    /**
     * Cleans up files in docsDir that shouldn't be there (i.e. files that are already uploaded)
     * @return void
     */
    public function as3DocumentsCleanupAction() {

        $this->logger->info("Starting AS3 local documents cleanup...");

        $protectedFiles = ['..', '.', ".gitkeep"];
        $fileNames = array_diff(scandir($this->config->directories->docsDir), $protectedFiles);

        if (count($fileNames) > 0) {

            foreach ($fileNames as $fileName) {

                // Extract document id from the file name
                $documentId = str_replace(".pdf", "", $fileName);

                // Using that ID, let's find the related DasDocument model
                $dasDocument = DasDocuments::findFirst([
                            "conditions" => "id = :id:",
                            "bind" => [
                                "id" => $documentId
                            ]
                ]);

                // If there is no DasDocument model for this file, delete it.
                // If there is a DasDocument model for this file, but it's already processed, delete it.
                if ($dasDocument === false || ($dasDocument !== false && $dasDocument->getAs3Processed() === true)) {

                    if (unlink($this->config->directories->docsDir . $fileName)) {
                        $this->logger->info("  Deleted {file_path}", [
                            "file_path" => $this->config->directories->docsDir . $fileName
                        ]);
                    }
                    else {
                        $this->logger->error("  Error deleting {file_path}", [
                            "file_path" => $this->config->directories->docsDir . $fileName
                        ]);
                    }
                }
            }
        }
        else {
            $this->logger->info("  There were no files to delete.");
        }

        $this->logger->info("Finished AS3 local documents cleanup.");

    }

    /**
     * Sends an email to a user with development application in which phrases were detected.
     * @return void
     */
    public function sendPhraseEmailsAction() {

        // First get the users we're sending emails to.
        $dasUsersRelations = DasUsers::find("1=1 GROUP BY users_id");
        foreach ($dasUsersRelations as $dasUsersRelation) {

            // DasUser is a relation between DA and User, so the actual user is $dasUser->User
            $user = $dasUsersRelation->User;

            // Get all the DAs with phrases in them
            $phql = "SELECT [Aiden\Models\DasUsers].*"
                    . " FROM [Aiden\Models\DasUsers]"
                    . " WHERE 1=1"
                    . " AND email_sent = 0"
                    . " AND users_id =:users_id:"
                    . " GROUP BY das_id"
                    . " ORDER BY created DESC";

            $dasQuery = $this->modelsManager->createQuery($phql);
            $dasUsersRelationsByUser = $dasQuery->execute([
                "users_id" => $user->getId()
            ]);

            // Skip if there aren't any relations to be processed.
            if (count($dasUsersRelationsByUser) === 0) {
                continue;
            }

            $emailBody = "";
            $totalPhrases = 0;
            $totalDas = 0;

            foreach ($dasUsersRelationsByUser as $dasUserRelationByUser) {

                $totalDas++;

                if ($user->getPhraseDetectEmail() === true) {

                    /*
                     * =========
                     * 
                     * Marrickville
                     * https://approvalbase.com/leads/1/view
                     * 
                     * To demolish part of the premises and construct a secondary dwelling at the rear of the existing dwelling
                     * 
                     * * demolish
                     * * dwelling
                     * 
                     * =========
                     */

                    $da = $dasUserRelationByUser->Da;

                    // Council Name + URL
                    $emailBody .= $da->Council->getName() . "\r\n";
                    $emailBody .= sprintf("%sleads/%s/view\r\n", $this->config->baseUri, $da->getId());
                    $emailBody .= "\r\n";

                    // Description
                    $emailBody .= "Description:\r\n";
                    $emailBody .= $da->getDescription() . "\r\n";
                    $emailBody .= "\r\n";

                    // Generate phrase lines: * <phrase>
                    $phrases = $da->getContainedPhrases($user->Phrases);
                    foreach ($phrases as $i => $phrase) {

                        // Add to total so we can generate a subject
                        $totalPhrases++;

                        $emailBody .= "\t" . ($i + 1) . ": " . $phrase->getPhrase();
                        $emailBody .= "\r\n";
                    }

                    // Horizontal line
                    $emailBody .= "\r\n";
                    $emailBody .= "=========\r\n";
                    $emailBody .= "\r\n";
                }
                else {

                    $dasUserRelationByUser->email_sent = true;
                    $dasUserRelationByUser->save();
                }
            }

            try {

                $mg = Mailgun::create($this->config->mailgun->apiKey);
                $response = $mg->messages()->send($this->config->mailgun->domain, [
                    'from' => $this->config->mailgun->fromEmail,
                    'to' => $user->getEmail(),
                    'subject' => sprintf(
                            "Hi %s, we found %s phrase%s in %s development application%s"
                            , $user->getName()
                            , $totalPhrases
                            , $totalPhrases > 1 ? "s" : ""
                            , $totalDas
                            , $totalDas > 1 ? "s" : ""
                    ),
                    'text' => $emailBody
                ]);

                if ($response->getMessage() === "Queued. Thank you.") {

                    // Inform database that email was sent for these relations
                    foreach ($dasUsersRelationsByUser as $dasUsersRelationByUser) {
                        $dasUsersRelationByUser->email_sent = true;
                        $dasUsersRelationByUser->save();
                    }
                }
                else {
                    $this->logger->error($response->getMessage());
                }
            }
            catch (\Mailgun\Exception\HttpClientException $e) {
                $this->logger->error($e->getMessage());
            }
        }

    }

}
