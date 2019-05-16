p<?php

use Aiden\Models\Das;

class BlacktownTask extends _BaseTask {

    public $council_name = "Blacktown";
    public $council_website_url = "https://www.blacktown.nsw.gov.au";
    public $council_params = [];
    public $council_default_param = "";

    public function scrapeAction($params = []) {

        $url = "https://services.blacktown.nsw.gov.au/webservices/scm/default.ashx?itemid=890";
        $url = "https://eservices.blacktown.nsw.gov.au/T1PRProd/WebApps/eProperty//P1/eTrack/eTrackApplicationSearchResults.aspx?Field=S&Period=LM&r=BCC.P1.WEBGUEST&f=%24P1.ETR.SEARCH.SLM";

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

        $elements = $html->find("tr[class=normalRow], tr[class=alternateRow]");
        foreach ($elements as $row) {

            // Get the council reference so we can check if the DA is known
            $councilTd = $row->find('td', 0);
            if ($councilTd === null) {
                continue;
            }

            $daCouncilReferenceElement = $this->cleanString($councilTd->innertext());
            $daCouncilReference = $this->get_string_between($daCouncilReferenceElement, 'value="', '" id="');
            $this->logger->info($daCouncilReference);

//            $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
//            $da->setCouncilId($this->getCouncil()->getId());
//            $da->setCouncilReference($daCouncilReference);

//            if ($da->save()) {
//
//                $this->logger->info("");
//                $this->logger->info("Created new development application {da_id} ({da_reference})", [
//                    "da_id" => $da->getId(),
//                    "da_reference" => $da->getCouncilReference()
//                ]);
//
//                $daHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($daElement->outertext());
//                if ($daHtml === false) {
//                    continue;
//                }
//
//                // All information is available in the XML, so no need to have a separate scrapeMetaAction()-method
//                $this->scrapeMeta($daHtml, $da);
//            }
//            else {
//                $this->logger->info("Could not save development application ({error})", ["error" => print_r($da->getMessages(), true)]);
//            }
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->logger->info("Done.");

    }

    public function scrapeMetaAction() {

        $this->logger->info("This council does not offer DA-specific pages, so all of the "
                . "information for a development application is pulled from the initial scrape()-method.");
        return false;

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;

        $addressElement = $html->find("PrimaryAddress", 0);
        if ($addressElement === null) {
            echo"ELement not found";
            return false;
        }

        $address = $this->cleanString($addressElement->innertext());

        $associatedLotElement = $html->find("AssociatedLot", 0);
        if ($associatedLotElement !== null) {

            $associatedLotString = $this->cleanString($associatedLotElement->innertext());
            if (strlen($associatedLotString) > 0) {

                $associatedLotArray = explode(",", $associatedLotString);
                foreach ($associatedLotArray as $associatedLot) {

                    if (strlen($associatedLot) === 0) {
                        continue;
                    }

                    $lotAddress = $this->cleanString($associatedLot) . ", " . $address;
                    if ($this->saveAddress($da, $lotAddress) === true) {
                        $addedAddresses++;
                    }
                }
            }
            else {

                if ($this->saveAddress($da, $address) === true) {
                    $addedAddresses++;
                }
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool {

        $applicantElement = $html->find("ApplicantName", 0);
        if ($applicantElement === null) {
            return false;
        }

        $role = "Applicant";
        $name = $this->cleanString($applicantElement->innertext());
        return $this->saveParty($da, $role, $name);

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $notesElement = $html->find("Notes", 0);
        if ($notesElement === null) {
            return false;
        }

        $value = $this->cleanString($notesElement->innertext());
        return $this->saveDescription($da, $value);

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        $estimatedCostElement = $html->find("EstimatedCost", 0);
        if ($estimatedCostElement === null) {
            return false;
        }

        $value = $this->cleanString($estimatedCostElement->innertext());
        return $this->saveEstimatedCost($da, $value);

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $lodgeDateElement = $html->find("LodgementDate", 0);
        if ($lodgeDateElement === null) {
            return false;
        }

        $value = $this->cleanString($lodgeDateElement->innertext());
        $dateParts = explode("T", $value);
        $date = \DateTime::createFromFormat("Y-m-d", $dateParts[0]);
        return $this->saveLodgeDate($da, $date);

    }

    protected function extractOfficers($html, $da, $params = null): bool {
        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool {
        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool {
        return false;

    }


    function get_string_between($string, $start, $end)
    {
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) return '';
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }


    public function postCurl($url, $formData, $requestHeaders)
    {

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $formData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);

        return [
            'output' => $output,
            'errno' => $errno,
            'errmsg' => $errmsg
        ];
    }

}
