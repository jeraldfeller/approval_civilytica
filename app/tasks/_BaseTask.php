<?php

use Aiden\Models\Das;
use Aiden\Models\DasAddresses;
use Aiden\Models\DasParties;
use Aiden\Models\DasDocuments;
use Aiden\Models\Councils;

abstract class _BaseTask extends \Phalcon\Cli\Task {

    protected $council;

    protected $council_name;

    protected $council_website_url;

    protected $council_params;

    protected $council_default_param;

    /**
     * Makes sure the council is existent, if not it'll create it. This method is executed before each scrape.
     * @return boolean
     */
    public function initialize() {

        // We need these values!
        if (!isset($this->council_name) || !isset($this->council_website_url)) {
            return false;
        }

        $relatedCouncil = Councils::findFirstByName($this->getCouncilName());
        if (!$relatedCouncil) {

            $logMsg = sprintf("Could not find related council model for [%s]", $this->getCouncilName());
            $this->logger->info($logMsg);

            // Create new
            $relatedCouncil = new Councils();
            $relatedCouncil->setName($this->council_name);
            $relatedCouncil->setWebsiteUrl($this->council_website_url);

            if ($relatedCouncil->save()) {
                $logMsg = sprintf("Created council [%s]", $this->getCouncilName());
                $this->logger->info($logMsg);
            }
        }

        $this->setCouncil($relatedCouncil);

    }

    /**
     * Scrapes development application meta information, like description, lodge date, estimated cost etc.
     * @return boolean
     */
    public function scrapeMetaAction() {

        $this->logger->info("Processing individual [{council_name}] development applications...", ["council_name" => $this->getCouncil()->getName()]);

        $das = $this->getCouncil()->getUncrawledDas();
        $this->logger->info("There are {da_amount} [{council_name}] development applications to crawl", [
            "da_amount" => count($das),
            "council_name" => $this->getCouncil()->getName()
        ]);

        foreach ($das as $i => $da) {

            // Some councils require terms to be accepted before being able to view specific DAs
            if ($i === 0 && method_exists($this, "acceptTerms")) {

                if ($this->acceptTerms($this->getAspFormDataByUrl($da->getCouncilUrl())) === false) {

                    // If we can't accept terms, try again next time.
                    $this->logger->warning("Terms could not be accepted. Stopping execution.");
                    return false;
                }
                else {
                    $this->logger->info("Accepted terms and conditions...");
                }
            }

            $this->logger->info("");
            $this->logger->info("Checking development application {da_id} ({da_reference})...", [
                "da_id" => $da->getId(),
                "da_reference" => $da->getCouncilReference()
            ]);
            $this->logger->info($da->getCouncilUrl());

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $da->getCouncilUrl());
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
            curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

            $output = curl_exec($ch);
            $errno = curl_errno($ch);
            $errmsg = curl_error($ch);
            curl_close($ch);

            if ($errno !== 0) {
                $this->logger->error("cURL error: {errmsg} ({errno}), stopping execution", ["errmsg" => $errmsg, "errno" => $errno]);
                continue;
            }

            $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
            if (!$html) {
                $this->logger->error("Could not parse HTML, stopping execution");
                continue;
            }

            // Extract addresses is a core method, without it don't run.
            if (method_exists($this, "extractAddresses")) {

                $extractedAddresses = $this->extractAddresses($html, $da);
                if ($extractedAddresses === false) {
                    $this->logger->error("Did not find any addresses related to this DA, something went wrong.");
                    continue;
                }
            }
            else {

                $this->logger->critical("Current class is not being able to extract address(es).");
                return false;
            }

            foreach ($this->getRequiredMethods() as $method) {
                if (method_exists($this, $method)) {
                    $this->{$method}($html, $da);
                }
            }

            $da->setCrawled(true);

            if ($da->save()) {
                $this->logger->info("Finished.");
            }
            else {
                $this->logger->info("Something went wrong when trying to update crawl status ({error})", print_r($da->getMessages(), true));
            }
        }

    }

    /**
     * Alternative way to scrape development application meta information, usually called by council controllers
     * of which the data is present on a single page.
     * @param type $daHtml
     * @param type $da
     * @param type $params
     * @return boolean
     */
    public function scrapeMeta($daHtml, $da, $params = null) {

        // Weird Phalcon bug doesn't allow us to save model twice without errors.
        $oldDaId = $da->getId();
        unset($da);
        $da = Das::findFirstById($oldDaId);
        if ($da === false) {
            $this->logger->critical("Could not find related development application in scrapeMeta()-method.");
            return false;
        }

        // Extract addresses is a core method, without it don't run.
        if (method_exists($this, "extractAddresses")) {

            $extractedAddresses = $this->extractAddresses($daHtml, $da, $params);
            if ($extractedAddresses === false) {
                $this->logger->error("Did not find any addresses related to this DA, something went wrong.");
                return false;
            }
        }
        else {

            $this->logger->critical("Current class is not being able to extract address(es).");
            return false;
        }

        foreach ($this->getRequiredMethods() as $method) {
            $this->{$method}($daHtml, $da, $params);
        }

        $da->setCrawled(true);
        if ($da->save()) {
            $this->logger->info("Finished.");
        }
        else {
            $this->logger->info("Something went wrong when trying to update crawl status ({error})", [
                "error" => print_r($da->getMessages(), true)
            ]);
        }

    }

    /**
     * Returns an array with the methods each development application must call
     * @return []
     */
    public function getRequiredMethods() {

        return [
            "extractLodgeDate",
            "extractDescription",
            "extractEstimatedCost",
            "extractPeople",
            "extractApplicants",
            "extractOfficers",
            "extractDocuments",
        ];

    }

    /**
     * Scrapes the council's development application
     */
    abstract public function scrapeAction($params = []);

    /**
     * Extracts addresses related to a development application
     * @return boolean
     */
    abstract protected function extractAddresses($html, $da, $params = null): bool;

    /**
     * Extracts the description related to a development application
     * @return boolean
     */
    abstract protected function extractDescription($html, $da, $params = null): bool;

    /**
     * Extracts people and their roles related to a development application
     * @return boolean
     */
    abstract protected function extractPeople($html, $da, $params = null): bool;

    /**
     * Extracts officers related to a development application
     */
    abstract protected function extractOfficers($html, $da, $params = null): bool;

    /**
     * Extracts applicants related to a development application
     * @return boolean
     */
    abstract protected function extractApplicants($html, $da, $params = null): bool;

    /**
     * Extracts documents related to a development application
     * @return boolean
     */
    abstract protected function extractDocuments($html, $da, $params = null): bool;

    /**
     * Extracts the estimated cost of the related development application
     * @return boolean
     */
    abstract protected function extractEstimatedCost($html, $da, $params = null): bool;

    /**
     * Extracts the lodge date of the related development application
     * @return boolean
     */
    abstract protected function extractLodgeDate($html, $da, $params = null): bool;

    /**
     * Creates a development application
     * @param type $da
     * @return boolean
     */
    public function saveDa($da) {

        if ($da->getId() != null && $da->save()) {

            $this->logger->info("Modified existing [{council_name}] development application [{da_id}] ({da_reference})", [
                "council_name" => $this->getCouncil()->getName(),
                "da_id" => $da->getId(),
                "da_reference" => $da->getCouncilReference()
            ]);
            return true;
        }
        else if ($da->getId() == null && $da->save()) {

            $this->logger->info("Created new [{council_name}] development application [{da_id}] ({da_reference})", [
                "council_name" => $this->getCouncil()->getName(),
                "da_id" => $da->getId(),
                "da_reference" => $da->getCouncilReference()
            ]);
            return true;
        }
        else {

            $this->logger->info("Could not create [{council_name}] development application ({error})", [
                "council_name" => $this->getCouncil()->getName(),
                "error" => print_r($da->getMessages(), true)
            ]);
            return false;
        }

    }

    /**
     * Creates a party related to a development application
     * @param type $da
     * @param type $role
     * @param type $name
     * @return boolean
     */
    public function saveParty($da, $role, $name) {

        $dasParty = DasParties::createIfNotExists($da->getId(), $role, $name);
        switch ($dasParty) {

            case DasParties::PARTY_SAVED:
                $this->logger->info(" Created related party [{role}] with value [{name}]", ["role" => $role, "name" => $name]);
                return true;

            case DasParties::PARTY_ERROR_SAVING:
                $this->logger->error(" Error creating related party [{role}] with value [{name}]", ["role" => $role, "name" => $name]);
                return false;

            case DasParties::PARTY_NO_NAME:
                $this->logger->error(" Error creating related party [{role}] with value [{name}], no name", ["role" => $role, "name" => $name]);
                return false;

            case DasParties::PARTY_EXISTS:
                $this->logger->notice(" Related party [{role}] with value [{name}] already exists, ignoring...", ["role" => $role, "name" => $name]);
                return true;
        }

    }

    /**
     * Creates the estimated cost related to a development application
     * @param type $da
     * @param type $estimatedCost
     * @return boolean
     */
    public function saveEstimatedCost($da, $estimatedCost) {

        $estimatedCost = filter_var($estimatedCost, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $estimatedCost = floatval($estimatedCost);

        if ($da->getEstimatedCost() == $estimatedCost) {

            $this->logger->notice(" Estimated cost did not change, ignoring...");
            return true;
        }
        else {

            if ($estimatedCost > 0) {

                $da->setEstimatedCost($estimatedCost);
                if ($da->save()) {

                    $this->logger->info(" Created estimated cost [{estimated_cost}]", [
                        "estimated_cost" => $estimatedCost
                    ]);
                    return true;
                }
                else {

                    $this->logger->error(" Error creating estimated cost [{estimated_cost}] ({error})", [
                        "estimated_cost" => $estimatedCost,
                        "error" => print_r($da->getMessages(), true)
                    ]);
                    return false;
                }
            }
        }

        return false;

    }

    /**
     * Creates the lodge date related to a development application
     * @param type $da
     * @param \DateTime $date
     * @return boolean
     */
    public function saveLodgeDate($da, $date) {

        if (($date instanceof \DateTime) === false) {
            $this->logger->error("Passed \$date [{date}] was not instanceof \DateTime", ["date" => $date]);
            return false;
        }

        $oldLodgeDate = $da->getLodgeDate();
        $date->setTime(0, 0, 0);


        if (is_a($oldLodgeDate, "DateTime") && $date->format("Y-m-d") === $oldLodgeDate->format("Y-m-d")) {

            $this->logger->notice(" Date did not change, skipping...");
            return true;
        }
        else {

            if ($date !== false && $oldLodgeDate !== $date) {

                $da->setLodgeDate($date);
                if ($da->save()) {

                    $this->logger->info(" Created lodge date [{date}]", ["date" => $date->format("r")]);
                    return true;
                }
                else {

                    $this->logger->error(" Error creating lodge date [{date}]", ["date" => $date->format("r")]);
                    return false;
                }
            }
        }

    }

    /**
     * Creates a document related to a development application
     * @param type $da
     * @param type $name
     * @param type $url
     * @param type $date
     * @return boolean
     */
    public function saveDocument($da, $name, $url, $date = null) {

        $daDocument = DasDocuments::createIfNotExists($da->getId(), $name, $url, $date);
        switch ($daDocument) {

            case DasDocuments::DOCUMENT_SAVED:
                $this->logger->info(" Created related document [{document_name}]", ["document_name" => $name]);
                return true;

            case DasDocuments::DOCUMENT_EXISTS:
                $this->logger->notice(" Document [{document_name}] already exists, ignoring...", ["document_name" => $name]);
                return true;

            case DasDocuments::DOCUMENT_NO_SAVED:
                $this->logger->error(" Error creating related document [{document_name}]", ["document_name" => $name]);
                return false;

            case DasDocuments::DOCUMENT_NO_NAME:
                $this->logger->error(" Error creating related document [{document_name}], no name", ["document_name" => $name]);
                return false;

            case DasDocuments::DOCUMENT_NO_URL:
                $this->logger->error(" Error creating related document [{document_name}], no URL", ["document_name" => $name]);
                return false;
        }

    }

    /**
     * Creates an address related to a development application
     * @param type $da
     * @param type $address
     * @return boolean
     */
    public function saveAddress($da, $address) {

        $daAddress = DasAddresses::createIfNotExists($da->getId(), $address);

        switch ($daAddress) {

            case DasAddresses::ADDRESS_CREATED:
                $this->logger->info(" Created related address [{address}]", ["address" => $address]);
                return true;

            case DasAddresses::ADDRESS_ERROR_ON_SAVE:
                $this->logger->error(" Error creating related address [{address}]", ["address" => $address]);
                return false;

            case DasAddresses::ADDRESS_EXISTS:
                $this->logger->notice(" Address [{address}] did not change, skipping...", ["address" => $address]);
                return true;

            case DasAddresses::ADDRESS_ZERO_LENGTH:
                $this->logger->notice(" Address with zero length detected (error ignored, address may be added later)...", ["address" => $address]);
                return true;
        }

    }

    /**
     * Creates a description related to a development application
     * @param type $da
     * @param type $newDescription
     * @return boolean
     */
    public function saveDescription($da, $newDescription) {

        $oldDescription = $da->getDescription();

        if ($oldDescription === $newDescription) {
            $this->logger->notice(" Description didn't change, skipping...");
            return true;
        }
        else {

            $da->setDescription($newDescription);
            if ($da->save()) {
                $this->logger->info(" Created description [{new}]", ["new" => $newDescription]);
                return true;
            }
            else {
                $this->logger->error(" Error creating description [{new}]", ["new" => $newDescription]);
                return false;
            }
        }

    }

    /**
     * Gets form data for ASP(x) powered websites by URL
     * @param type $url
     * @return boolean
     */
    public function getAspFormDataByUrl($url) {

        $requestHeaders = [
            'Accept: */*; q=0.01',
            'Accept-Encoding: none'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);

        curl_close($ch);

        // No errors
        if ($errno !== 0) {
            // TODO: Log
            return false;
        }

        $formData = $this->getAspFormDataByString($output);
        return $formData;

    }

    /**
     * Gets form data for ASP(x) powered websites by string
     * @param type $url
     * @return boolean
     */
    public function getAspFormDataByString($string) {

        // Extract __VIEWSTATE, __VIEWSTATEGENERATOR, and other asp puke
        $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($string);
        if (!$html) {
            // TODO: Log that HTML couldn't be parsed.
            return false;
        }

        $formData = [];

        $elements = $html->find("input[type=hidden]");
        foreach ($elements as $element) {

            if (isset($element->id) && isset($element->value)) {
                $formData[$element->id] = html_entity_decode($element->value, ENT_QUOTES);
            }
        }

        return $formData;

    }

    /**
     * Sets the council
     * @param string $council
     */
    public function setCouncil($council) {

        $this->council = $council;

    }

    /**
     * Gets the council
     * @return string
     */
    public function getCouncil() {
        return $this->council;

    }

    /**
     * Sets the council name
     * @param string $council_name
     */
    public function setCouncilName($council_name) {
        $this->council_name = $council_name;

    }

    /**
     * Gets the council name
     * @return string
     */
    public function getCouncilName() {
        return $this->council_name;

    }

    /**
     * Sets the council website URL
     * @param string $council_website_url
     */
    public function setCouncilWebsiteUrl($council_website_url) {
        $this->council_website_url = $council_website_url;

    }

    /**
     * Gets the council website URL
     * @return string
     */
    public function getCouncilWebsiteUrl() {
        return $this->council_website_url;

    }

    /**
     * Sets the council scraper description
     * @return string
     */
    public function getCouncilScraperDescription() {
        return $this->council_scraper_description;

    }

    /**
     * Gets the council scraper description
     * @param string $council_scraper_description
     */
    public function setCouncilScraperDescription($council_scraper_description) {
        $this->council_scraper_description = $council_scraper_description;

    }

    /**
     * Cleans a string by removing HTML entities, replacing (multiple) spaces, and trimming it
     * @param string $string
     * @return string
     */
    public static function cleanString($string) {

        $string = html_entity_decode($string, ENT_QUOTES);
        $string = str_replace("\xc2\xa0", ' ', $string);
        $string = trim($string);
        $string = preg_replace('!\s+!', ' ', $string);

        return $string;

    }

}
