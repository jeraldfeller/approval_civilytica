<?php

use Aiden\Models\Das;
use Aiden\Models\DasAddresses;

class AshfieldTask extends _BaseTask {

    public $council_name = "Ashfield";
    public $council_website_url = "http://www.ashfield.nsw.gov.au/";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";
    public $inner_west_id = 17;

    private function acceptTerms() {

        $url = "http://mycouncil.solorient.com.au/Horizon/logonGuest.aw?domain=horizondap_ashfield#/home";

        $formData = http_build_query([
            "<root><cancel_process_action process_id" => '"74370" process_group_id"'
        ]);

        $requestHeaders = [
            "Host: mycouncil.solorient.com.au",
            "Accept: */*",
            "Accept-Language: en-GB,en;q=0.5",
            "Accept-Encoding: none",
            "Referer: http://mycouncil.solorient.com.au/Horizon/logonGuest.aw?domain=horizondap_ashfield",
            "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
            "X-Requested-With: XMLHttpRequest",
            "Content-Length: " . strlen($formData),
            "DNT: 1",
            "Connection: keep-alive",
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $formData);
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

        if ($errno !== 0) {

            $logMsg = sprintf("cURL error: [%s] (%s)", $errmsg, $errno);
            $this->logger->info($logMsg);
            return false;
        }

    }

    public function scrapeAction($params = []) {

        $this->logger->info("--------------Ashfield--------------------");

        if (!isset($params[0])) {
            return false;
        }

        $query = "FIND Applications";
        switch ($params[0]) {

            case "thisweek":
                $query .= " WHERE WEEK(Applications.Lodged)=CURRENT_WEEK-1";
                $query_name = "SubmittedThisWeek";
                break;

            case "lastweek":
                $query .= " WHERE WEEK(Applications.Lodged-1)=CURRENT_WEEK-2";
                $query_name = "SubmittedLastWeek";
                break;

            case "thismonth":
                $query .= " WHERE MONTH(Applications.Lodged)=CURRENT_MONTH";
                $query_name = "SubmittedThisMonth";
                break;

            case "lastmonth":
                $query .= " WHERE MONTH(Applications.Lodged-1)=SystemSettings.SearchMonthPrevious";
                $query_name = "SubmittedLastMonth";
                break;

            default:
                return false;
        }


        $url = 'http://mycouncil.solorient.com.au/Horizon/urlRequest.aw?actionType=run_query_action&query_string=FIND+Applications+WHERE+MONTH(Applications.Lodged)%3DCURRENT_MONTH+AND+YEAR(Applications.Lodged)%3DCURRENT_YEAR+ORDER+BY+Applications.AppYear+DESC%2CApplications.AppNumber+DESC&query_name=SubmittedThisMonth&take=50&skip=0&start=0&pageSize=10000';

        // Send request to page which in turn sets a cookie allowing us to scrape results
        $this->acceptTerms();
        $this->logger->info($url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
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
            $this->logger->error("cURL error: {errmsg} ({errno})", ["errmsg" => $errmsg, "errno" => $errno]);
            return false;
        }

        $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
        if (!$html) {
            $this->logger->error("Could not parse HTML");
            return false;
        }

        $daElements = $html->find("row");
        foreach ($daElements as $daElement) {

            $daHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($daElement->innertext());
            if ($daHtml === false) {
                $this->logger->error("Unable to parse development application XML");
                continue;
            }

            // Get Council Reference
            $daCouncilReferenceElement = $daHtml->find("AccountNumber", 0);
            if ($daCouncilReferenceElement === null) {
                continue;
            }

            $daCouncilReference = $this->cleanString($daCouncilReferenceElement->{"org_value"});

            $da = Das::exists($this->inner_west_id, $daCouncilReference) ?: new Das();
            $da->setCouncilId($this->inner_west_id);
            $da->setCouncilReference($daCouncilReference);

            // Get alternative council reference
            $daCouncilReferenceAltElement = $daHtml->find("id", 0);
            if ($daCouncilReferenceAltElement) {

                $daCouncilReferenceAlt = $this->cleanString($daCouncilReferenceAltElement->innertext());
                $da->setCouncilReferenceAlt($daCouncilReferenceAlt);
            }

            $this->saveDa($da);
        }

        $this->getCouncil()->setLastScrape(new \DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $this->logger->info("Done.");

    }

    public function scrapeMetaAction() {

        $this->logger->info("Processing individual [{council_name}] development applications...", [
            "council_name" => $this->getCouncil()->getName()
        ]);

        $das = $this->getCouncil()->getUncrawledDas();
        $this->logger->info("There are {da_amount} [{council_name}] development applications to crawl", [
            "da_amount" => count($das),
            "council_name" => $this->getCouncil()->getName()
        ]);

        foreach ($das as $i => $da) {

            // Some councils require terms to be accepted before being able to view specific DAs
            if ($i === 0 && method_exists($this, "acceptTerms")) {

                if ($this->acceptTerms() === false) {

                    // If we can't accept terms, try again next time.
                    $this->logger->warning("Terms could not be accepted. Stopping execution.");
                    continue;
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
            if (strlen($da->getCouncilUrl()) > 0) {
                $this->logger->info($da->getCouncilUrl());
            }

            $formData = sprintf('<root>'
                    . '<get_form_data_action object_name=\'Applications\' '
                    . 'form_name=\'Public\' form_context=\'view\' '
                    . 'object_id=\'%s\' '
                    . 'screen_width="1366" '
                    . 'screen_height="483" '
                    . 'screen_orientation="landscape" />'
                    . '</root>', $da->getCouncilReferenceAlt());

            $requestHeaders = [
                "Host: mycouncil.solorient.com.au",
                "Accept: */* ",
                "Accept-Language: en-GB,en;q=0.5",
                "Accept-Encoding: none",
                "Referer: http://mycouncil.solorient.com.au/Horizon/logonGuest.aw?domain=horizondap_ashfield",
                "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
                "X-Requested-With: XMLHttpRequest",
                "Content-Length: " . strlen($formData),
            ];

            $url = "http://mycouncil.solorient.com.au/Horizon/webif.awr";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $formData);
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
                $this->logger->error("cURL error: {errmsg} ({errno})", ["errmsg" => $errmsg, "errno" => $errno]);
                continue;
            }

            $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
            if ($html === false) {
                $this->logger->error("Unable to parse development application XML (scrapeMeta())");
                continue;
            }

            // Extract addresses is a core method, without it don't run.
            if (method_exists($this, "extractAddresses")) {
                $extractedAddresses = $this->extractAddresses($html, $da);
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

            if ($da->save()) {
                $this->logger->info("Finished.");
            }
            else {
                $this->logger->info("Something went wrong when trying to update crawl status ({error})", [
                    "error" => print_r($da->getMessages(), true)
                ]);
            }
        }

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;

        $propertyElements = $html->find("attribute_data[attribute_name=Property]");
        foreach ($propertyElements as $propertyElement) {

            $standard_attribute_dataElement = $propertyElement->children(0);
            if ($standard_attribute_dataElement === null) {
                continue;
            }

            $attribute_data_valElement = $standard_attribute_dataElement->children(0);
            if ($attribute_data_valElement === null) {
                continue;
            }

            $value = $this->cleanString($attribute_data_valElement->innertext());

            if ($this->saveAddress($da, $value) === DasAddresses::ADDRESS_CREATED) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool {

        $addedApplicants = 0;
        $applicantElements = $html->find("attribute_data[attribute_name=Applicant]");

        foreach ($applicantElements as $applicantElement) {

            $standard_attribute_dataElement = $applicantElement->children(0);
            if ($standard_attribute_dataElement === null) {
                continue;
            }

            $attribute_data_valElement = $standard_attribute_dataElement->children(0);
            if ($attribute_data_valElement === null) {
                continue;
            }

            $value = $this->cleanString($attribute_data_valElement->innertext());
            if (strlen($value) > 0 && $this->saveParty($da, "Applicant", $value) === true) {
                $addedApplicants++;
            }
        }

        return ($addedApplicants > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $descriptionElement = $html->find("attribute_data[attribute_name=Description]", 0);
        if ($descriptionElement === null) {
            return false;
        }

        $standard_attribute_dataElement = $descriptionElement->children(0);
        if ($standard_attribute_dataElement === null) {
            return false;
        }

        $attribute_data_valElement = $standard_attribute_dataElement->children(0);
        if ($attribute_data_valElement === null) {
            return false;
        }

        $value = $this->cleanString($attribute_data_valElement->innertext());
        return $this->saveDescription($da, $value) === true;

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        $estimatedCostElement = $html->find("attribute_data[attribute_name=EstimatedCost]", 0);
        if ($estimatedCostElement === null) {
            return false;
        }

        $standard_attribute_dataElement = $estimatedCostElement->children(0);
        if ($standard_attribute_dataElement === null) {
            return false;
        }

        $attribute_data_valElement = $standard_attribute_dataElement->children(0);
        if ($attribute_data_valElement === null) {
            return false;
        }

        $value = $this->cleanString($attribute_data_valElement->innertext());
        return $this->saveEstimatedCost($da, $value) === true;

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $lodgedElement = $html->find("attribute_data[attribute_name=Lodged]", 0);
        if ($lodgedElement === null) {
            return false;
        }

        $standard_attribute_dataElement = $lodgedElement->children(0);
        if ($standard_attribute_dataElement === null) {
            return false;
        }

        $attribute_data_valElement = $standard_attribute_dataElement->children(0);
        if ($attribute_data_valElement === null) {
            return false;
        }

        $value = $this->cleanString($attribute_data_valElement->innertext());
        $date = \DateTime::createFromFormat("d/m/Y", $value);
        return $this->saveLodgeDate($da, $date) === true;

    }

    protected function extractOfficers($html, $da, $params = null): bool {

        $addedOfficers = 0;
        $officerElements = $html->find("attribute_data[attribute_name=Officer]");

        foreach ($officerElements as $officerElement) {

            $standard_attribute_dataElement = $officerElement->children(0);
            if ($standard_attribute_dataElement === null) {
                continue;
            }

            $attribute_data_valElement = $standard_attribute_dataElement->children(0);
            if ($attribute_data_valElement === null) {
                continue;
            }

            $value = $this->cleanString($attribute_data_valElement->innertext());
            if ($this->saveParty($da, "Officer", $value) === true) {
                $addedOfficers++;
            }
        }

        return ($addedOfficers > 0);

    }

    protected function extractPeople($html, $da, $params = null): bool {
        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool {
        return false;

    }

}
