<?php

/*
 * Poggit
 *
 * Copyright (C) 2016-2017 Poggit
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace poggit\release\details;

use poggit\account\Session;
use poggit\ci\builder\ProjectBuilder;
use poggit\Config;
use poggit\Mbd;
use poggit\Meta;
use poggit\module\Module;
use poggit\release\details\review\PluginReview;
use poggit\release\Release;
use poggit\resource\ResourceManager;
use poggit\utils\internet\Mysql;
use poggit\utils\lang\Lang;
use poggit\utils\OutputManager;

class ReleaseDetailsModule extends Module {
    private $doStateReplace = false;
    private $release;

    private $projectName;
    private $name;
    private $shortDesc;
    private $version;
    private $description;
    private $license;
    private $licenseDisplayStyle;
    private $licenseText;
    private $keywords;
    private $categories;
    private $mainCategory;
    private $spoons;
    private $permissions;
    private $deps;
    private $assocs;
    private $parentRelease;
    private $reqr;
    private $descType;
    private $icon;
    private $state;
    private $artifact;

    private $buildInternal;
    private $buildCount;
    private $thisBuildCommit;
    private $lastBuildCommit;
    private $topReleaseCommit;
    private $buildCompareURL;
    private $releaseCompareURL;
    private $lastBuildInternal;
    private $lastReleaseCreated;
    private $lastReleaseInternal;
    private $lastReleaseClass;
    private $lastBuildClass;
    private $changelogText;
    private $changelogType;
    private $totalupvotes;
    private $totaldownvotes;
    private $myvote;
    private $myvotemessage;
    private $authors;

    public function getName(): string {
        return "release";
    }

    public function getAllNames(): array {
        return ["release", "rel", "plugin", "p"];
    }

    public function output() {
        $minifier = OutputManager::startMinifyHtml();
        $parts = Lang::explodeNoEmpty("/", $this->getQuery(), 2);
        $preReleaseCond = (!isset($_REQUEST["pre"]) or (isset($_REQUEST["pre"]) and $_REQUEST["pre"] != "off")) ? "(1 = 1)" : "((r.flags & 2) = 2)";
        $stmt = /** @lang MySQL */
            "SELECT r.releaseId, r.name, UNIX_TIMESTAMP(r.creation) AS created, b.sha, b.cause AS cause,  
                UNIX_TIMESTAMP(b.created) AS buildcreated, UNIX_TIMESTAMP(r.updateTime) AS stateupdated,
                r.shortDesc, r.version, r.artifact, r.buildId, r.licenseRes, artifact.type AS artifactType, artifact.dlCount AS dlCount, 
                r.description, descr.type AS descrType, r.icon, r.parent_releaseId,
                r.changelog, changelog.type AS changeLogType, r.license, r.flags, r.state, b.internal AS internal, b.class AS class,
                rp.owner AS author, rp.name AS repo, p.name AS projectName, p.projectId, p.path AS projectPath, p.lang AS hasTranslation,
                (SELECT COUNT(*) FROM releases r3 WHERE r3.projectId = r.projectId)
                FROM releases r
                INNER JOIN projects p ON r.projectId = p.projectId
                INNER JOIN repos rp ON p.repoId = rp.repoId
                INNER JOIN resources artifact ON r.artifact = artifact.resourceId
                INNER JOIN resources descr ON r.description = descr.resourceId
                INNER JOIN resources changelog ON (r.changelog = changelog.resourceId OR changelog.resourceId = 1)
                INNER JOIN builds b ON r.buildId = b.buildId
                WHERE r.name = ? AND $preReleaseCond ORDER BY LEAST(r.state, ?) DESC, created DESC";
        if(count($parts) === 0) Meta::redirect("plugins");
        if(count($parts) === 1) {
            $author = null;
            $name = $parts[0];
            $projects = Mysql::query($stmt, "si", $name, Release::STATE_VOTED);
            if(count($projects) === 0) Meta::redirect("plugins?term=" . urlencode($name) . "&error=" . urlencode("No plugins called $name"));
            $release = $projects[0];
            if(count($projects) > 1) {
                $this->topReleaseCommit = json_decode($projects[1]["cause"])->commit;
                $this->lastReleaseInternal = (int) $projects[1]["internal"];
                $this->lastReleaseClass = ProjectBuilder::$BUILD_CLASS_HUMAN[$projects[1]["class"]];
                $this->lastReleaseCreated = (int) $projects[1]["buildcreated"];
            }
        } else {
            assert(count($parts) === 2);
            list($name, $requestedVersion) = $parts;
            $projects = Mysql::query($stmt, "si", $name, Release::STATE_VOTED);

            if(count($projects) > 1) {
                $i = 0;
                foreach($projects as $project) {
                    $i++;
                    if($project["version"] == $requestedVersion) {
                        $release = $project;
                        if($i > 1) {
                            $this->topReleaseCommit = json_decode($projects[0]["cause"])->commit;
                            $this->lastReleaseInternal = $projects[0]["internal"];
                            $this->lastReleaseClass = ProjectBuilder::$BUILD_CLASS_HUMAN[$projects[0]["class"]];
                            $this->lastReleaseCreated = (int) $projects[0]["buildcreated"];
                            break;
                        } else {
                            $this->topReleaseCommit = json_decode($projects[1]["cause"])->commit;
                            $this->lastReleaseInternal = $projects[1]["internal"];
                            $this->lastReleaseClass = ProjectBuilder::$BUILD_CLASS_HUMAN[$projects[1]["class"]];
                            $this->lastReleaseCreated = (int) $projects[1]["buildcreated"];
                            break;
                        }
                    }
                }
                $this->doStateReplace = true;
                if(!isset($release)) Meta::redirect((count($projects) > 0 ? "p/" : "plugins?term=") . urlencode($name));
            } else {
                if(count($projects) > 0) { // exactly 1 result
                    $release = $projects[0];
                } else {
                    Meta::redirect("plugins?term=" . urlencode($name));
                }
            }
        }
        /** @var array $release */
        $this->release = $release;
        $session = Session::getInstance();
        $user = $session->getName();
        $uid = $session->getUid();

        $allBuilds = Mysql::query("SELECT buildId, cause, internal, class FROM builds b WHERE b.projectId = ? ORDER BY buildId DESC", "i", $this->release["projectId"]);
        $this->buildCount = count($allBuilds);
        $getnext = false;
        foreach($allBuilds as $buildRow) {
            $cause = json_decode($buildRow["cause"]);
            if(!isset($cause)) continue;
            if($getnext) {
                if($this->lastReleaseClass . $this->lastReleaseInternal != $buildRow["class"] . $buildRow["internal"]) {
                    $this->lastBuildCommit = $cause->commit ?? 0;
                    $this->lastBuildInternal = (int) $buildRow["internal"];
                    $this->lastBuildClass = ProjectBuilder::$BUILD_CLASS_HUMAN[$buildRow["class"]];
                }
                break;
            }
            if($buildRow["buildId"] == $this->release["buildId"]) {
                $this->thisBuildCommit = $cause->commit ?? 0;
                $getnext = true;
            }
        }
        $this->buildCompareURL = ($this->lastBuildCommit && $this->thisBuildCommit) ? "http://github.com/" . urlencode($this->release["author"]) . "/" .
            urlencode($this->release["repo"]) . "/compare/" . $this->lastBuildCommit . "..." . $this->thisBuildCommit : "";
        $this->releaseCompareURL = ($this->topReleaseCommit && $this->thisBuildCommit && ($this->lastReleaseCreated < $this->release["buildcreated"])) ? "http://github.com/" . urlencode($this->release["author"]) . "/" .
            urlencode($this->release["repo"]) . "/compare/" . $this->topReleaseCommit . "..." . $this->thisBuildCommit : "";

        $this->release["description"] = (int) $this->release["description"];
        $descType = Mysql::query("SELECT type FROM resources WHERE resourceId = ? LIMIT 1", "i", $this->release["description"]);
        $this->release["desctype"] = $descType[0]["type"];
        $this->release["releaseId"] = (int) $this->release["releaseId"];
        $this->release["buildId"] = (int) $this->release["buildId"];
        $this->release["internal"] = (int) $this->release["internal"];
        // Changelog
        $this->release["changelog"] = (int) $this->release["changelog"];
        if($this->release["changelog"] !== ResourceManager::NULL_RESOURCE) {
            $clTypeRow = Mysql::query("SELECT type FROM resources WHERE resourceId = ? LIMIT 1", "i", $this->release["changelog"]);
            $this->release["changelogType"] = $clTypeRow[0]["type"] ?? null;
        } else {
            $this->release["changelog"] = null;
            $this->release["changelogType"] = null;
        }
        // Keywords
        $keywordRow = Mysql::query("SELECT word FROM release_keywords WHERE projectId = ?", "i", $this->release["projectId"]);
        $this->release["keywords"] = [];
        foreach($keywordRow as $row) {
            $this->release["keywords"][] = $row["word"];
        }
        // Categories
        $categoryRow = Mysql::query("SELECT category, isMainCategory FROM release_categories WHERE projectId = ?", "i", $this->release["projectId"]);
        $this->release["categories"] = [];
        $this->release["maincategory"] = 1;
        foreach($categoryRow as $row) {
            if($row["isMainCategory"] == 1) {
                $this->release["maincategory"] = (int) $row["category"];
            } else {
                $this->release["categories"][] = (int) $row["category"];
            }
        }
        // Spoons
        $this->release["spoons"] = [];
        $spoons = Mysql::query("SELECT since, till FROM release_spoons WHERE releaseId = ?", "i", $this->release["releaseId"]);
        if(count($spoons) > 0) {
            foreach($spoons as $row) {
                $this->release["spoons"]["since"][] = $row["since"];
                $this->release["spoons"]["till"][] = $row["till"];
            }
        }
        //Permissions
        $this->release["permissions"] = [];
        $perms = Mysql::query("SELECT val FROM release_perms WHERE releaseId = ?", "i", $this->release["releaseId"]);
        if(count($perms) > 0) {
            foreach($perms as $row) {
                $this->release["permissions"][] = $row["val"];
            }
        }
        // Associated
        $this->release["assocs"] = [];
        $this->parentRelease = Mysql::query("SELECT releaseId, name, version, artifact FROM releases WHERE releaseId = ?", "i", $this->release["parent_releaseId"])[0] ?? null;
        if($this->parentRelease) {
            $this->release["assocs"]["name"][] = $this->parentRelease["name"];
            $this->release["assocs"]["version"][] = $this->parentRelease["version"];
            $this->release["assocs"]["artifact"][] = $this->parentRelease["artifact"];
            $this->release["assocs"]["parent"][] = true;
        }
        $assocs = Mysql::query("SELECT releaseId, name, version, artifact FROM releases WHERE parent_releaseId = ? AND releaseId !=?", "ii", $this->parentRelease["releaseId"] ?? $this->release["releaseId"], $this->release["releaseId"]);
        if(count($assocs) > 0) {
            foreach($assocs as $row) {
                $this->release["assocs"]["name"][] = $row["name"];
                $this->release["assocs"]["version"][] = $row["version"];
                $this->release["assocs"]["artifact"][] = $row["artifact"];
                $this->release["assocs"]["parent"][] = false;
            }
        }
        // Dependencies
        $this->release["deps"] = [];
        $deps = Mysql::query("SELECT name, version, depRelId, isHard FROM release_deps WHERE releaseId = ?", "i", $this->release["releaseId"]);
        if(count($deps) > 0) {
            foreach($deps as $row) {
                $this->release["deps"]["name"][] = $row["name"];
                $this->release["deps"]["version"][] = $row["version"];
                $this->release["deps"]["depRelId"][] = (int) $row["depRelId"];
                $this->release["deps"]["isHard"][] = (int) $row["isHard"];
            }
        }
        // Requirements
        $this->release["reqr"] = [];
        $reqr = Mysql::query("SELECT type, details, isRequire FROM release_reqr WHERE releaseId = ?", "i", $this->release["releaseId"]);
        if(count($reqr) > 0) {
            foreach($reqr as $row) {
                $this->release["reqr"]["type"][] = $row["type"];
                $this->release["reqr"]["details"][] = $row["details"];
                $this->release["reqr"]["isRequire"][] = (int) $row["isRequire"];
            }
        }
        //Votes
        $myvote = Mysql::query("SELECT vote, message FROM release_votes WHERE releaseId = ? AND user = ?", "ii", $this->release["releaseId"], $uid);
        $this->myvote = (count($myvote) > 0) ? $myvote[0]["vote"] : 0;
        $this->myvotemessage = (count($myvote) > 0) ? $myvote[0]["message"] : "";
        $totalvotes = Mysql::query("SELECT a.votetype, COUNT(a. votetype) AS votecount
                    FROM (SELECT IF( rv.vote > 0,'upvotes','downvotes') AS votetype FROM release_votes rv WHERE rv.releaseId = ?) AS a
                    GROUP BY a. votetype", "i", $this->release["releaseId"]);
        foreach($totalvotes as $votes) {
            if($votes["votetype"] == "upvotes") {
                $this->totalupvotes = $votes["votecount"];
            } else {
                $this->totaldownvotes = $votes["votecount"];
            }
        }

        $this->state = (int) $this->release["state"];
        $isStaff = Meta::getAdmlv($user) >= Meta::ADMLV_MODERATOR;
        $isMine = strtolower($user) === strtolower($this->release["author"]);
        if((($this->state < Config::MIN_PUBLIC_RELEASE_STATE && !$session->isLoggedIn()) || $this->state < Release::STATE_CHECKED && $session->isLoggedIn()) && (!$isMine && !$isStaff)) {
            Meta::redirect("p?term=" . urlencode($name) . "&error=" . urlencode("You are not allowed to view this resource"));
        }
        $this->projectName = $this->release["projectName"];
        $this->name = $this->release["name"];
        $this->buildInternal = $this->release["internal"];
        $this->description = ($this->release["description"]) ? file_get_contents(ResourceManager::getInstance()->getResource($this->release["description"])) : "No Description";
        $this->version = $this->release["version"];
        $this->shortDesc = $this->release["shortDesc"];
        $this->licenseDisplayStyle = ($this->release["license"] == "custom") ? "display: true" : "display: none";
        $this->licenseText = ($this->release["licenseRes"]) ? file_get_contents(ResourceManager::getInstance()->getResource($this->release["licenseRes"])) : "";
        $this->license = $this->release["license"];
        $this->changelogText = ($this->release["changelog"]) ? file_get_contents(ResourceManager::getInstance()->getResource($this->release["changelog"])) : "";
        $this->changelogType = ($this->release["changelogType"]) ? $this->release["changelogType"] : "md";
        $this->keywords = ($this->release["keywords"]) ? implode(" ", $this->release["keywords"]) : "";
        $this->categories = ($this->release["categories"]) ? $this->release["categories"] : [];
        $this->authors = [];
        foreach(Mysql::query("SELECT uid, name, level FROM release_authors WHERE projectId = ?",
            "i", $this->release["projectId"]) as $row) {
            $this->authors[(int) $row["level"]][(int) $row["uid"]] = $row["name"];
        }
        ksort($this->authors, SORT_NUMERIC);
        foreach($this->authors as $level => $authors) {
            asort($this->authors[$level], SORT_STRING);
        }
        $this->spoons = ($this->release["spoons"]) ? $this->release["spoons"] : [];
        $this->permissions = ($this->release["permissions"]) ? $this->release["permissions"] : [];
        $this->deps = ($this->release["deps"]) ? $this->release["deps"] : [];
        $this->assocs = ($this->release["assocs"]) ? $this->release["assocs"] : [];
        $this->reqr = ($this->release["reqr"]) ? $this->release["reqr"] : [];
        $this->mainCategory = ($this->release["maincategory"]) ? $this->release["maincategory"] : 1;
        $this->descType = $this->release["desctype"] ? $this->release["desctype"] : "md";
        $this->icon = $this->release["icon"];
        $this->artifact = (int) $this->release["artifact"];

        $earliestDate = (int) Mysql::query("SELECT MIN(UNIX_TIMESTAMP(creation)) AS created FROM releases WHERE projectId = ?",
            "i", (int) $release["projectId"])[0]["created"];
//        $tags = Poggit::queryAndFetch("SELECT val FROM release_meta WHERE releaseId = ? AND type = ?", "ii", (int) $release["releaseId"], (int)ReleaseConstants::TYPE_CATEGORY);
        ?>
        <html>
        <head prefix="og: http://ogp.me/ns# fb: http://ogp.me/ns/fb# object: http://ogp.me/ns/object# article: http://ogp.me/ns/article# profile: http://ogp.me/ns/profile#">
            <title><?= htmlspecialchars($release["name"]) ?></title>
            <meta property="article:published_time" content="<?= date(DATE_ISO8601, $earliestDate) ?>"/>
            <meta property="article:modified_time" content="<?= date(DATE_ISO8601, (int) $release["created"]) ?>"/>
            <meta property="article:author" content="<?= Mbd::esq($release["name"]) ?>"/>
            <meta property="article:section" content="Plugins"/>
            <?php $this->headIncludes($release["name"], $release["shortDesc"], "article", "", explode(" ", $this->keywords)) ?>
            <meta name="twitter:image:src" content="<?= Mbd::esq($this->icon ?? "") ?>">
            <script>
                var releaseDetails = <?= json_encode([
                    "name" => $this->name,
                    "version" => $this->version,
                    "project" => [
                        "repo" => [
                            "owner" => $this->release["author"],
                            "name" => $this->release["repo"]
                        ],
                        "path" => $this->release["projectPath"],
                        "name" => $this->release["projectName"]
                    ],
                    "build" => [
                        "buildId" => $this->release["buildId"],
                        "sha" => $this->release["sha"],
                        "tree" => $this->release["sha"] ? ("tree/{$this->release["sha"]}/") : "",
                    ]
                ]) ?>;
            </script>
        </head>
        <body>
        <?php $this->bodyHeader() ?>
        <div id="body">
            <div class="release-top">
                <?php
                $editLink = Meta::root() . "update/" . $this->release["author"] . "/" . $this->release["repo"] . "/" . $this->projectName . "/" . $this->buildInternal;
                $user = Session::getInstance()->getName();
                if($user === $this->release["author"] || Meta::getAdmlv($user) >= Meta::ADMLV_REVIEWER) { ?>
                    <div class="editrelease">
                        <span class="action" onclick="location.href='<?= Mbd::esq($editLink) ?>'">Edit Release</span>
                    </div>
                <?php } ?>
                <?php if(Meta::getAdmlv($user) >= Meta::ADMLV_MODERATOR) { ?>
                    <div class="editRelease">
                        <div id="adminRejectionDialog">
                            <p>Rejection dialog</p>
                            <textarea cols="80" rows="10" id="adminRejectionTextArea"><?php
                                $ciPath = Meta::getSecret("meta.extPath") . "ci/" . $this->release["author"] . "/" . $this->release["name"] . "/$this->projectName";
                                $submitDate = date("Y-m-d H:i:s", $this->release["created"]);
                                echo htmlspecialchars("Dear @{$this->release["author"]},\n" .
                                    "I am sorry to inform you that your submitted release, \"{$this->release["name"]}\" " .
                                    "(v{$this->version}), for the project [{$this->projectName}]({$ciPath}) on $submitDate " .
                                    "has been rejected.\n\n\n\n" .
                                    "Please resolve the above-listed issues and submit the updated plugin again.");
                                ?></textarea>
                        </div>
                        <script>
                            var adminRejectionDialog = $("#adminRejectionDialog").dialog({
                                title: "Reject plugin",
                                autoOpen: false,
                                height: 400,
                                width: 300,
                                position: {my: "center top", at: "center top+100", of: window},
                                modal: true,
                                buttons: {
                                    Reject: function() {
                                        ghApi(<?= json_encode(
                                                "repos/{$this->release["author"]}/{$this->release["repo"]}/commits/{$this->release["sha"]}/comments")?>,
                                            {
                                                body: $("#adminRejectionTextArea").val()
                                            }, "POST", function() {
                                                ajax("release.statechange", {
                                                    data: {
                                                        relId: relId,
                                                        state: <?= json_encode(Release::STATE_REJECTED) ?>
                                                    },
                                                    method: "POST",
                                                    success: function() {
                                                        location = location.href;
                                                    }
                                                });
                                            }
                                        );
                                    }
                                }
                            });
                        </script>
                        <span class="action" onclick="adminRejectionDialog.dialog('open')">Reject with message</span>
                        <select id="setStatus" class="inlineselect">
                            <?php foreach(Release::$STATE_ID_TO_HUMAN as $key => $name) { ?>
                                <option value="<?= $key ?>" <?= $this->state == $key ? "selected" : "" ?>><?= $name ?></option>
                            <?php } ?>
                        </select>
                        <span class="update-status" onclick="updateRelease()">Set Status</span>
                    </div>
                <?php } ?>
            </div>
            <div class="plugin-heading">
                <div class="plugin-title">
                    <h3>
                        <a href="<?= Meta::root() ?>p/<?= $this->parentRelease["name"] ?>/<?= $this->parentRelease["version"] ?>">
                            <?= $this->parentRelease["name"] ? htmlspecialchars($this->parentRelease["name"]) . " > " : "" ?>
                            <a href="<?= Meta::root() ?>ci/<?= $this->release["author"] ?>/<?= $this->release["repo"] ?>/<?= urlencode(
                                $this->projectName) ?>">
                                <?= htmlspecialchars($this->release["name"]) ?>
                                <?php
                                $tree = $this->release["sha"] ? ("tree/" . $this->release["sha"]) : "";
                                Mbd::ghLink("https://github.com/{$this->release["author"]}/{$this->release["repo"]}/$tree/{$this->release["projectPath"]}");
                                ?>
                            </a>
                    </h3>
                    <h4>by
                        <a href="<?= Meta::root() . "plugins/by/" . $this->release["author"] ?>"><?= $this->release["author"] ?></a>
                    </h4>
                </div>
                <div class="plugin-header-info">
                    <span id="releaseState"
                          class="plugin-state-<?= $this->state ?>"><?= htmlspecialchars(Release::$STATE_ID_TO_HUMAN[$this->state]) ?></span>
                    <?php if($this->version !== "") { ?>
                        <div class="plugin-info">
                            Version<h5><?= htmlspecialchars($this->version) ?></h5>
                        </div>
                    <?php } ?>
                    <?php if($this->shortDesc !== "") { ?>
                        <div class="plugin-info">
                            <p>Summary <h5><?= htmlspecialchars($this->shortDesc) ?></h5></p>
                        </div>
                    <?php } ?></div>
                <div class="plugin-logo">
                    <?php if($this->icon === null) { ?>
                        <img src="<?= Meta::root() ?>res/defaultPluginIcon2.png" height="128"/>
                    <?php } else { ?>
                        <img src="<?= Mbd::esq($this->icon) ?>" height="128"/>
                    <?php } ?>
                </div>
            </div>
            <?php if($this->state === Release::STATE_CHECKED) { ?>
                <div class="release-warning"><h5>
                        This is a "Checked" version. Poggit reviewers found no obviously unsafe code, but it has not
                        been carefully tested yet. <i>Use at your own risk!</i>
                    </h5></div>
            <?php } ?>
            <div class="plugin-top">
                <div class="plugin-top-left">
                    <?php $link = Meta::root() . "r/" . $this->artifact . "/" . $this->projectName . ".phar"; ?>
                    <div class="downloadrelease">
                        <p><a href="<?= $link ?>">
                            <span onclick='window.location = <?= json_encode($link, JSON_UNESCAPED_SLASHES) ?>;'
                                  class="action">Direct Download</span>
                            </a>
                            Open version:
                            <select id="releaseVersionHistory"
                                    onchange='window.location = getRelativeRootPath() + "p/" + <?= json_encode($this->release["name"]) ?> + "/" + this.value;'>
                                <?php foreach(Mysql::query("SELECT version, state, UNIX_TIMESTAMP(updateTime) AS updateTime
                                    FROM releases WHERE projectId = ? ORDER BY creation DESC",
                                    "i", $this->release["projectId"]) as $row) {
                                    if(!$isMine && !$isStaff && $row["state"] < Config::MIN_PUBLIC_RELEASE_STATE) continue;
                                    ?>
                                    <option value="<?= htmlspecialchars($row["version"], ENT_QUOTES) ?>"
                                        <?= $row["version"] === $this->release["version"] ? "selected" : "" ?>
                                    ><?= htmlspecialchars($row["version"]) ?>
                                        (<?= date('d M Y', $row["updateTime"]) ?>
                                        ) <?= Release::$STATE_ID_TO_HUMAN[$row["state"]] ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </p>
                    </div>
                    <div class="buildcount"><h6>
                            Submitted on <?= htmlspecialchars(date('d M Y', $this->release["created"])) ?> from
                            <a href="<?= Meta::root() ?>ci/<?= $this->release["author"] ?>/<?= urlencode($this->release["repo"]) ?>/<?= urlencode($this->projectName) ?>/<?= $this->buildInternal ?>">
                                Dev Build #<?= $this->buildInternal ?></a>,
                            <?= Release::$STATE_ID_TO_HUMAN[$this->state] ?> on
                            <?= htmlspecialchars(date('d M Y', $this->release["stateupdated"])) ?>
                        </h6></div>
                    <?php if($this->releaseCompareURL != "") { ?>
                        <div class="release-compare-link"><a target="_blank" href="<?= $this->releaseCompareURL ?>"><h6>
                                    Compare <?= $this->lastReleaseClass ?>#<?= $this->lastReleaseInternal ?> - latest
                                    release build</h6> <?php Mbd::ghLink($this->releaseCompareURL) ?></a></div>
                    <?php } ?>
                </div>
                <?php if(count($this->spoons) > 0) { ?>
                    <div class="plugin-info-wrapper">
                        <div class="form-key">Supported API versions</div>
                        <div class="plugin-info">
                            <table class="info-table" id="supportedSpoonsValue">
                                <?php foreach($this->spoons["since"] as $key => $since) { ?>
                                    <tr class="submit-spoonEntry">
                                        <td>
                                            <div class="submit-spoonVersion-from">
                                                <div><?= $since ?></div>
                                            </div>
                                        </td>
                                        <td style="border:none;"> -</td>
                                        <td>
                                            <div class="submit-spoonVersion-to">
                                                <div><?= ($this->spoons["till"][$key]) ?></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </table>
                        </div>
                    </div>
                <?php } ?>
                <?php if(count($this->assocs) > 0) { ?>
                    <div class="plugin-info-wrapper">
                        <div class="form-key">Associated Plugins</div>
                        <div class="plugin-info">
                            <table class="info-table" id="associatedValue">
                                <?php foreach($this->assocs["name"] as $key => $name) {
                                    $link = Meta::root() . "p/" . $name . "/" . $this->assocs["version"][$key];
                                    $pharlink = Meta::root() . "r/" . $this->assocs["artifact"][$key] . "/" . $name . ".phar";
                                    ?>
                                    <tr>
                                        <td><span type="text"
                                                  class="submit-assocName <?= $this->assocs["parent"][$key] ? "assoc-parent" : "" ?>"><?= $name ?> <?= $this->assocs["version"][$key] ?></span>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-default btn-sm text-center"><a
                                                        href="<?= $pharlink ?>">Download</a>
                                            </button>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-default btn-sm text-center"><a
                                                        href="<?= $link ?>">View Plugin</a>
                                            </button>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </table>
                        </div>
                    </div>
                <?php } ?>
            </div>
            <div id="release-authors" data-owner="<?= $this->release["author"] ?>">
                <?php if(count($this->authors) > 0) { ?>
                    <h4>Authors <?php Mbd::displayAnchor("authors") ?></h4>
                    <ul id="release-authors-main">
                        <?php foreach($this->authors as $level => $authors) { ?>
                            <li class="release-authors-level">
                                <?= Release::$AUTHOR_TO_HUMAN[$level] ?>s:
                                <ul>
                                    <?php foreach($authors as $uid => $name) { ?>
                                        <li class="release-authors-entry" data-name="<?= $name ?>">
                                            <img src="https://avatars1.githubusercontent.com/u/<?= $uid ?>" width="16"/>
                                            @<?= $name ?> <?php Mbd::ghLink("https://github.com/$name") ?>
                                        </li>
                                    <?php } ?>
                                </ul>
                            </li>
                        <?php } ?>
                    </ul>
                <?php } ?>
            </div>
            <div class="review-wrapper">
                <div class="plugin-table">
                    <div class="plugin-info-description">
                        <div class="release-description-header">
                            <div class="release-description">Plugin
                                Description <?php Mbd::displayAnchor("description") ?></div>
                            <?php if($this->release["state"] == Release::STATE_CHECKED) { ?>
                                <div id="upvote" class="upvotes<?= $session->isLoggedIn() ? " vote-button" : "" ?>"><img
                                            src='<?= Meta::root() ?>res/voteup.png'><?= $this->totalupvotes ?? "0" ?>
                                </div>
                                <div id="downvote" class="downvotes<?= $session->isLoggedIn() ? " vote-button" : "" ?>">
                                    <img
                                            src='<?= Meta::root() ?>res/votedown.png'><?= $this->totaldownvotes ?? "0" ?>
                                </div>
                            <?php } ?>
                            <?php if(Session::getInstance()->isLoggedIn() && !$isMine) { ?>
                                <div id="addreview" class="action review-release-button">Review This Release</div>
                            <?php } ?>
                        </div>
                        <div class="plugin-info" id="release-description-content">
                            <?= $this->descType === "txt" ? ("<pre>" . htmlspecialchars($this->description) . "</pre>") : $this->description ?>
                        </div>
                    </div>
                    <?php if($this->changelogText !== "") { ?>
                        <div class="plugin-info-changelog">
                            <div class="form-key">What's new <?php Mbd::displayAnchor("changelog") ?></div>
                            <div class="plugin-info" id="release-changelog-content">
                                <?= $this->changelogType === "txt" ? "<pre>$this->changelogText</pre>" : $this->changelogText ?>
                            </div>
                        </div>
                    <?php } ?>
                    <?php if(count($this->deps) > 0) { ?>
                        <div class="plugin-info-wrapper">
                            <div class="form-key">Dependencies</div>
                            <div class="plugin-info">
                                <table class="info-table" id="dependenciesValue">
                                    <?php foreach($this->deps["name"] as $key => $name) {
                                        $link = Meta::root() . "p/" . $name . "/" . $this->deps["version"][$key];
                                        ?>
                                        <tr>
                                            <td><span type="text"
                                                      class="submit-depName"><?= $name ?> <?= $this->deps["version"][$key] ?></span>
                                            </td>
                                            <td>
                                                <span> <?= $this->deps["isHard"][$key] == 1 ? "Required" : "Optional" ?></span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-default btn-sm text-center"><a
                                                            href="<?= $link ?>">View Plugin</a>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </table>
                            </div>
                        </div>
                    <?php } ?>
                    <div class="plugin-info-wrapper">
                        <div class="form-key">License <?php Mbd::displayAnchor("license") ?></div>
                        <div class="plugin-info">
                            <p><?php echo $this->license ?? "None" ?></p>
                            <textarea readonly id="submit-customLicense" style="<?= $this->licenseDisplayStyle ?>"
                                      placeholder="Custom license content"
                                      rows="10"><?= $this->licenseText ?></textarea>
                        </div>
                    </div>
                    <div class="plugin-info-wrapper">
                        <div class="form-key">
                            <nobr>Pre-release</nobr>
                        </div>
                        <div class="plugin-info">
                            <p><?php echo $this->release["flags"] == Release::FLAG_PRE_RELEASE ? "Yes" : "No" ?></p>
                        </div>
                    </div>
                    <div class="plugin-info-wrapper">
                        <div class="form-key">Categories:</div>
                        <div class="plugin-info main-category"><?= Release::$CATEGORIES[$this->mainCategory] ?></div>
                        <?php foreach($this->categories as $id => $index) { ?>
                            <div class="plugin-info"><?= Release::$CATEGORIES[$index] ?></div>
                        <?php } ?>
                    </div>
                    <div class="plugin-info-wrapper">
                        <div class="form-key">Keywords</div>
                        <div class="plugin-info">
                            <p><?= $this->keywords ?></p>
                        </div>
                    </div>
                    <?php if(count($this->permissions) > 0) { ?>
                        <div class="plugin-info-wrapper">
                            <div class="form-key">Permissions</div>
                            <div class="plugin-info">
                                <div id="submit-perms" class="submit-perms-wrapper">
                                    <?php foreach($this->permissions as $reason => $perm) { ?>
                                        <div><?= htmlspecialchars(Release::$PERMISSIONS[$perm]["name"]) ?></div>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                    <?php if(count($this->reqr) > 0) { ?>
                        <div class="plugin-info-wrapper">
                            <div class="form-key">Requirements/<br/>Enhancements</div>
                            <div class="plugin-info">
                                <div id="submit-req">
                                    <table class="info-table" id="reqrValue">
                                        <tr>
                                            <th>Type</th>
                                            <th>Details</th>
                                            <th>Required?</th>
                                        </tr>
                                        <tr id="baseReqrForm" class="submit-reqrEntry" style="display: none;">
                                            <td>
                                                <select class="submit-reqrType" disabled>
                                                    <option value="mail">Mail server
                                                    </option>
                                                    <option value="mysql">MySQL database</option>
                                                    <option value="apiToken">Service API token
                                                    </option>
                                                    <option value="password">Passwords for services provided by the
                                                        plugin
                                                    </option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </td>
                                            <td><input type="text" class="submit-reqrSpec" disabled/></td>
                                            <td>
                                                <select class="submit-reqrEnhc" disabled>
                                                    <option value="requirement">Requirement</option>
                                                    <option value="enhancement">Enhancement</option>
                                                </select>
                                            </td>
                                        </tr>
                                        <?php if(count($this->reqr) > 0) {
                                            foreach($this->reqr["type"] as $key => $type) { ?>
                                                <tr class="submit-reqrEntry">
                                                    <td>
                                                        <select class="submit-reqrType" disabled>
                                                            <option value="mail" <?= $type == 1 ? "selected" : "" ?>>
                                                                Mail server
                                                            </option>
                                                            <option value="mysql" <?= $type == 2 ? "selected" : "" ?>>
                                                                MySQL database
                                                            </option>
                                                            <option value="apiToken" <?= $type == 3 ? "selected" : "" ?>>
                                                                Service API token
                                                            </option>
                                                            <option value="password" <?= $type == 4 ? "selected" : "" ?>>
                                                                Passwords for services provided by the plugin
                                                            </option>
                                                            <option value="other" <?= $type == 5 ? "selected" : "" ?>>
                                                                Other
                                                            </option>
                                                        </select>
                                                    </td>
                                                    <td><input type="text" class="submit-reqrSpec"
                                                               value="<?= $this->reqr["details"][$key] ?>"/ disabled>
                                                    </td>
                                                    <td>
                                                        <select class="submit-reqrEnhc" disabled>
                                                            <option value="requirement" <?= $this->reqr["isRequire"][0] == 1 ? "selected" : "" ?>>
                                                                Requirement
                                                            </option>
                                                            <option value="enhancement" <?= $this->reqr["isRequire"][0] == 0 ? "selected" : "" ?>>
                                                                Enhancement
                                                            </option>
                                                        </select>
                                                    </td>
                                                </tr>
                                            <?php }
                                        } ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
                <div class="review-panel">
                    <?php PluginReview::displayReleaseReviews([$this->release["releaseId"]]) ?>
                </div>
            </div>

            <div class="bottomdownloadlink">
                <p>
                    <?php
                    $link = Meta::root() . "r/" . $this->artifact . "/" . $this->projectName . ".phar";
                    ?>
                    <a href="<?= $link ?>">
                    <span class="action" onclick='window.location = <?= json_encode($link, JSON_UNESCAPED_SLASHES) ?>;'>
                        Direct Download</span></a>
                </p>
            </div>
        </div>
        <?php $this->bodyFooter() ?>
        <?php if(!$isMine) { ?>
            <!-- REVIEW DIALOGUE -->
            <div id="review-dialog" title="Review <?= $this->projectName ?>">
                <form>
                    <label author="author"><h3><?= $user ?></h3></label>
                    <textarea id="reviewmessage"
                              maxlength="<?= Meta::getAdmlv($user) >= Meta::ADMLV_MODERATOR ? 1024 : 256 ?>" rows="3"
                              cols="20" class="reviewmessage"></textarea>
                    <!-- Allow form submission with keyboard without duplicating the dialog button -->
                    <input type="submit" tabindex="-1" style="position:absolute; top:-1000px">
                </form>
                <?php if(Meta::getAdmlv($user) < Meta::ADMLV_MODERATOR) { ?>
                    <div class="reviewwarning"><p>You can leave one review per plugin release, and delete or update your
                            review at any time</p></div>
                <?php } ?>
                <form action="#">
                    <label for="votes">Rate this Plugin </label>
                    <select name="votes" id="votes">
                        <option>0</option>
                        <option>1</option>
                        <option>2</option>
                        <option selected>3</option>
                        <option>4</option>
                        <option>5</option>
                    </select>
                </form>
                <?php
                if(Meta::getAdmlv($user) >= Meta::ADMLV_MODERATOR) { ?>
                    <form action="#">
                        <label for="reviewcriteria">Criteria</label>
                        <select name="reviewcriteria" id="reviewcriteria">
                            <?php
                            $usedcrits = PluginReview::getUsedCriteria($this->release["releaseId"], PluginReview::getUIDFromName($user));
                            $usedcritslist = array_map(function($usedcrit) {
                                return $usedcrit['criteria'];
                            }, $usedcrits);
                            foreach(PluginReview::$CRITERIA_HUMAN as $key => $criteria) { ?>
                                <option value="<?= $key ?>" <?= in_array($key, $usedcritslist) ? "hidden='true'" : "selected" ?>><?= $criteria ?></option>
                            <?php } ?>
                        </select>
                    </form>
                <?php } ?>
            </div>
        <?php } ?>
        <?php if($session->isLoggedIn() && $this->release["state"] == Release::STATE_CHECKED) { ?>
            <!-- VOTING DIALOGUES -->
            <div id="voteup-dialog" title="Voting <?= $this->projectName ?>">
                <form>
                    <label plugin="plugin"><h4><?= $this->projectName ?></h4></label>
                    <?php if($this->myvote > 0) { ?>
                        <label><h6>You have already voted to ACCEPT this plugin</h6></label>
                    <?php } elseif($this->myvote < 0) { ?>
                        <label><h6>You previously voted to REJECT this plugin</h6></label>
                        <label><h6>Click below to change your vote</h6></label>
                    <?php } else { ?>
                        <label <h6>Poggit users can vote to accept or reject 'Checked' plugins</h6></label>
                        <label <h6>Please click 'Accept' to accept this plugin</h6></label>
                    <?php } ?>
                    <!-- Allow form submission with keyboard without duplicating the dialog button -->
                    <input type="submit" tabindex="-1" style="position:absolute; top:-1000px">
                </form>
            </div>
            <div id="votedown-dialog" title="Voting <?= $this->projectName ?>">
                <form>
                    <label plugin="plugin"><h4><?= $this->projectName ?></h4></label>
                    <?php if($this->myvote > 0) { ?>
                        <label><h6>You previously voted to ACCEPT this plugin</h6></label>
                        <label><h6>Click below to REJECT, and leave a short reason. The reason will only be visible to
                                admins to prevent abuse.</h6></label>
                    <?php } elseif($this->myvote < 0) { ?>
                        <label><h6>You have already voted to REJECT this plugin</h6></label>
                        <label><h6>Click below to confirm and update the reason. The reason will only be visible to
                                admins to prevent abuse.</h6></label>
                    <?php } else { ?>
                        <label <h6>Poggit users can vote to accept or reject 'Checked' plugins</h6></label>
                        <label <h6>Please click 'REJECT' to reject this plugin, and leave a short reason below. The
                            reason will only be visible to admins to prevent abuse.</h6></label>
                    <?php } ?>
                    <textarea id="votemessage"
                              maxlength="255" rows="3"
                              cols="20" class="votemessage"><?= $this->myvotemessage ?? "" ?></textarea>
                    <!-- Allow form submission with keyboard without duplicating the dialog button -->
                    <input type="submit" tabindex="-1" style="position:absolute; top:-1000px">
                    <label id="vote-error" class="vote-error"></label>
                </form>
            </div>
        <?php } ?>
        <script>
            var relId = <?= $this->release["releaseId"] ?>;
            var modalPosition = {my: "center top", at: "center top+100", of: window};

            <?php if (!$isMine){ ?>
            var reviewdialog, reviewform;

            // REVIEWING
            function doAddReview() {
                var criteria = $("#reviewcriteria").val();
                var user = "<?= Session::getInstance()->getName() ?>";
                var type = <?= Meta::getAdmlv($user) >= Meta::ADMLV_MODERATOR ? 1 : 2 ?>;
                var cat = <?= $this->mainCategory ?>;
                var score = $("#votes").val();
                var message = $("#reviewmessage").val();
                addReview(relId, user, criteria, type, cat, score, message);

                reviewdialog.dialog("close");
                return true;
            }

            reviewdialog = $("#review-dialog").dialog({
                title: "Poggit Review",
                autoOpen: false,
                height: 380,
                width: 250,
                position: modalPosition,
                modal: true,
                buttons: {
                    Cancel: function() {
                        reviewdialog.dialog("close");
                    },
                    "Post Review": doAddReview
                },
                open: function(event, ui) {
                    $('.ui-widget-overlay').bind('click', function() {
                        $("#review-dialog").dialog('close');
                    });
                },
                close: function() {
                    reviewform[0].reset();
                }
            });

            reviewform = reviewdialog.find("form").on("submit", function(event) {
                event.preventDefault();
            });

            $("#addreview").button().on("click", function() {
                reviewdialog.dialog("open");
            });
            <?php } ?>
            $(function() {
                var voteupdialog, voteupform, votedowndialog, votedownform;

                <?php if ($session->isLoggedIn() && $this->release["state"] == Release::STATE_CHECKED) { ?>
                // VOTING
                function doUpVote() {
                    var message = $("#votemessage").val();
                    var vote = 1;
                    addVote(relId, vote, message);
                    voteupdialog.dialog("close");
                    return true;
                }

                function doDownVote() {
                    var message = $("#votemessage").val();
                    if(message.length < 10) {
                        $("#vote-error").text("Please type at least 10 characters...");
                        return;
                    }
                    var vote = -1;
                    addVote(relId, vote, message);
                    votedowndialog.dialog("close");
                    return true;
                }

                voteupdialog = $("#voteup-dialog").dialog({
                    title: "ACCEPT Plugin",
                    autoOpen: false,
                    height: 300,
                    width: 250,
                    position: modalPosition,
                    modal: true,
                    buttons: {
                        Cancel: function() {
                            voteupdialog.dialog("close");
                        },
                        <?php if($this->myvote <= 0) { ?>
                        Accept: doUpVote
                        <?php } ?>
                    },
                    open: function(event, ui) {
                        $('.ui-widget-overlay').bind('click', function() {
                            $("#voteup-dialog").dialog('close');
                        });
                    },
                    close: function() {
                        voteupform[0].reset();
                    }
                });
                voteupform = voteupdialog.find("form").on("submit", function(event) {
                    event.preventDefault();
                });

                $("#upvote").button().on("click", function() {
                    voteupdialog.dialog("open");
                });

                votedowndialog = $("#votedown-dialog").dialog({
                    title: "REJECT Plugin",
                    autoOpen: false,
                    height: 380,
                    width: 250,
                    position: modalPosition,
                    modal: true,
                    buttons: {
                        Cancel: function() {
                            votedowndialog.dialog("close");
                        },
                        "Reject": doDownVote
                    },
                    open: function(event, ui) {
                        $('.ui-widget-overlay').bind('click', function() {
                            $("#votedown-dialog").dialog('close');
                        });
                    },
                    close: function() {
                        votedownform[0].reset();
                    }
                });
                votedownform = votedowndialog.find("form").on("submit", function(event) {
                    event.preventDefault();
                });

                $("#downvote").button().on("click", function() {
                    votedowndialog.dialog("open");
                });
                <?php } ?>
            });
        </script>
        <?php $this->includeJs("releaseDetails") ?>
        </body>
        </html>
        <?php
        OutputManager::endMinifyHtml($minifier);
    }
}
