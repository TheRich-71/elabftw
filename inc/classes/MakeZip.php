<?php
/********************************************************************************
*                                                                               *
*   Copyright 2012 Nicolas CARPi (nicolas.carpi@gmail.com)                      *
*   http://www.elabftw.net/                                                     *
*                                                                               *
********************************************************************************/

/********************************************************************************
*  This file is part of eLabFTW.                                                *
*                                                                               *
*    eLabFTW is free software: you can redistribute it and/or modify            *
*    it under the terms of the GNU Affero General Public License as             *
*    published by the Free Software Foundation, either version 3 of             *
*    the License, or (at your option) any later version.                        *
*                                                                               *
*    eLabFTW is distributed in the hope that it will be useful,                 *
*    but WITHOUT ANY WARRANTY; without even the implied                         *
*    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR                    *
*    PURPOSE.  See the GNU Affero General Public License for more details.      *
*                                                                               *
*    You should have received a copy of the GNU Affero General Public           *
*    License along with eLabFTW.  If not, see <http://www.gnu.org/licenses/>.   *
*                                                                               *
********************************************************************************/
namespace Elabftw\Elabftw;

use \Elabftw\Elabftw\MakePdf;
use \ZipArchive;
use \Exception;

class MakeZip
{
    private $idList;
    private $idArr = array();

    private $zipRealName;
    private $zipRelativePath;
    private $zipAbsolutePath;

    private $zip;
    private $table;
    private $filesToDelete = array();
    private $zdate;
    private $cleanTitle;

    private $firstname;
    private $lastname;


    /*
     * Provide it with the $_GET['id'] and $_GET['type']
     *
     */
    public function __construct($idList, $type)
    {
        // we check first if the zip extension is here
        if (!class_exists('ZipArchive')) {
            throw new Exception("You are missing the ZipArchive class in php. Uncomment the line extension=zip.so in /etc/php/php.ini.");
        }

        $this->idList = $idList;
        $this->table = $type;
        $this->checkTable();

        $this->zipRealName = hash("sha512", uniqid(rand(), true)) . ".zip";
        $this->zipRelativePath = 'uploads/tmp/' . $this->zipRealName;
        $this->zipAbsolutePath = ELAB_ROOT . $this->zipRelativePath;
        $this->setFirstLastName();
        $this->createZipArchive();
        $this->loopIdArr();

    }
    /*
     * Pretty straightforward
     *
     * @return string the path of the zip (uploads/tmp/<hash>.zip)
     */
    public function getZipRelativePath()
    {
        return $this->zipRelativePath;
    }

    /*
     * This is the name of the file that will get downloaded
     *
     */
    public function getZipName()
    {
        $ext = '.elabftw.zip';

        if (count($this->idArr) === 1) {
            return $this->zdate . "-" . $this->cleanTitle . $ext;
        } else {
            return kdate() . $ext;
        }
    }

    /*
     * Initiate the zip object and the archive
     *
     */
    private function createZipArchive()
    {

        $this->zip = new \ZipArchive;
        if (!$this->zip->open($this->zipAbsolutePath, ZipArchive::CREATE)) {
            throw new Exception('Could not open zip file!');
        }

    }

    /*
     * Validate the $_GET['type'] we have
     *
     */
    private function checkTable()
    {
        $correctValuesArr = array('experiments', 'items');
        if (!in_array($this->table, $correctValuesArr)) {
            throw new Exception('Bad type!');
        }
    }

    /*
     * Add a MANIFEST file at the root of the zip for listing files inside the zip.
     *
     */
    private function addManifest()
    {
        // add the MANIFEST file that lists the files in archive
        $manifest = "";
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $manifest .= $this->zip->getNameIndex($i) . "\n";
        }
        // add info about the creator + timestamp
        $manifest .= "\nZip archive created by " . $this->firstname . " " . $this->lastname . " on " . date('Y.m.d') . " at " . date('H:i:s') . ".\n";
        $manifest .= "~~~\neLabFTW - Free open source lab manager - http://www.elabftw.net\n";
        // fix utf8
        $manifest = utf8_encode($manifest);
        $manifest = "\xEF\xBB\xBF" . $manifest;
        $manifestpath = ELAB_ROOT . 'uploads/tmp/manifest-' . uniqid();
        $tf = fopen($manifestpath, 'w+');
        fwrite($tf, $manifest);
        fclose($tf);
        $this->zip->addFile($manifestpath, "MANIFEST");
        $this->filesToDelete[] = $manifestpath;
    }

    private function setFirstLastName()
    {
        global $pdo;

        // SQL to get firstname + lastname
        $sql = "SELECT firstname, lastname FROM users WHERE userid = :userid";
        $req = $pdo->prepare($sql);
        $req->bindParam(':userid', $_SESSION['userid']);
        $req->execute();
        $users = $req->fetch();
        $this->firstname = $users['firstname'];
        $this->lastname = $users['lastname'];

    }

    /*
     * This is where the magic happens
     *
     */
    private function addToZip($id)
    {
        global $pdo;

        // SQL to get info on the item we are zipping
        if ($this->table === 'experiments') {
            $sql = "SELECT * FROM experiments WHERE id = :id LIMIT 1";
            $req = $pdo->prepare($sql);
            $req->bindParam(':id', $id, \PDO::PARAM_INT);
            $req->execute();
            $zipped = $req->fetch();
            if ($zipped['userid'] != $_SESSION['userid']) {
                throw new Exception(_("You are trying to download an experiment you don't own!"));
            }

        } else {
            $sql = "SELECT items.*,
                items_types.name AS items_typesname
                FROM items
                LEFT JOIN items_types ON (items.type = items_types.id)
                WHERE items.id = :id LIMIT 1";
            $req = $pdo->prepare($sql);
            $req->bindParam(':id', $id, PDO::PARAM_INT);
            $req->execute();
            $zipped = $req->fetch();
            if ($zipped['team'] != $_SESSION['team_id']) {
                throw new Exception(_("You are trying to download an item you don't own!"));
            }
        }

        // make a title without special char for folder inside .zip
        $this->cleanTitle = preg_replace('/[^A-Za-z0-9]/', '_', stripslashes($zipped['title']));
        $this->zdate = $zipped['date'];
        // name of the folder
        // folder begin with date for experiments
        if ($this->table == 'experiments') {
            $folder = $this->zdate . "-" . $this->cleanTitle;
        } else { // items
            $itemtype = $zipped['items_typesname'];
            $folder = $itemtype . " - " . $this->cleanTitle;
        }
        $body = stripslashes($zipped['body']);
        $req->closeCursor();


        // SQL to get tags
        $sql = "SELECT tag FROM " . $this->table . "_tags WHERE item_id = $id";
        $req = $pdo->prepare($sql);
        $req->execute();
        $tags = null;
        while ($data = $req->fetch()) {
            $tags .= stripslashes($data['tag']) . ' ';
        }

        // add the .asn1 token to the zip archive if the experiment is timestamped
        if ($this->table === 'experiments' && $zipped['timestamped'] == 1) {
            // SQL to get the path of the token
            $sql = "SELECT real_name, long_name FROM uploads WHERE item_id = :id AND type = 'timestamp-token' LIMIT 1";
            $req = $pdo->prepare($sql);
            $req->bindParam(':id', $id);
            $req->execute();
            $token = $req->fetch();
            // add it to the .zip
            $this->zip->addFile(ELAB_ROOT . 'uploads/' . $token['long_name'], $folder . "/" . $token['real_name']);
        }

        // SQL to get filesattached (of the right type)
        $sql = "SELECT * FROM uploads WHERE item_id = :id AND (type = :type OR type = 'exp-pdf-timestamp')";
        $req = $pdo->prepare($sql);
        $req->bindParam(':id', $id);
        $req->bindParam(':type', $this->table);
        $req->execute();
        $real_name = array();
        $long_name = array();
        $comment = array();
        while ($uploads = $req->fetch()) {
            $real_name[] = $uploads['real_name'];
            $long_name[] = $uploads['long_name'];
            $comment[] = $uploads['comment'];
        }

        // files attached ?
        $filenb = count($real_name);
        if ($filenb > 0) {
            for ($i = 0; $i < $filenb; $i++) {
                // add files to archive
                $this->zip->addFile(ELAB_ROOT . 'uploads/' . $long_name[$i], $folder . "/" . $real_name[$i]);
            }
        }

        // add PDF to archive
        $pdf = new \Elabftw\Elabftw\MakePdf($id, $this->table);
        $mpdf = new \mPDF();

        $mpdf->SetAuthor($pdf->author);
        $mpdf->SetTitle($pdf->title);
        $mpdf->SetSubject('eLabFTW pdf');
        $mpdf->SetKeywords($pdf->tags);
        $mpdf->SetCreator('www.elabftw.net');
        $mpdf->WriteHTML($pdf->content);
        $pdfPath = ELAB_ROOT . 'uploads/tmp/' . hash("sha512", uniqid(rand(), true)) . '.pdf';
        $mpdf->Output($pdfPath, 'F');
        $this->zip->addFile($pdfPath, $folder . '/' . $pdf->getFileName());

        // add CSV file to archive
        $csvPath = make_unique_csv($id, $this->table);
        $this->zip->addFile($csvPath, $folder . "/" . $this->cleanTitle . ".csv");

        // add the export.txt file that is helpful for importing
        // first line is title, rest is body
        $txt = $pdf->title . "\n" . $body . "\n";
        // fix utf8
        $txt = utf8_encode($txt);
        $txtPath = ELAB_ROOT . 'uploads/tmp/' . hash("sha512", uniqid(rand(), true)) . '.txt';
        $tf = fopen($txtPath, 'w+');
        fwrite($tf, $txt);
        fclose($tf);
        // add the export.txt file as hidden file, users don't need to see it
        $this->zip->addFile($txtPath, $folder . "/.export.txt");
        // add the path of the files to be deleted in the files_to_delete array
        // (csv, export file and pdf)
        $this->filesToDelete[] = $csvPath;
        $this->filesToDelete[] = $txtPath;
        $this->filesToDelete[] = $pdfPath;

    }

    /*
     * Loop on each id and add it to our zip archive
     * This could be called the main function.
     *
     */
    private function loopIdArr()
    {
        $this->idArr = explode(" ", $this->idList);
        foreach ($this->idArr as $id) {
            if (!is_pos_int($id)) {
                throw new Exception('Bad id.');
            }
            $this->addToZip($id);
        }
        $this->addManifest();
        $this->zip->close();
    }


    /*
     * Clean up the temporary files (csv, txt and pdf)
     *
     */
    public function __destruct()
    {
        foreach ($this->filesToDelete as $file) {
            unlink($file);
        }
    }
}