<?php
declare(strict_types=1);
require __DIR__ . '/system/ui/layout.php';
ob_start(); ?>
<section class="hero"><span class="badge">Dokumentation</span><h1>Projektübersicht</h1><p>Dieser Build vervollständigt die äußere Enterprise-Hülle, ohne den bestehenden DataForm5-Core fachlich umzubauen.</p></section>
<h2>Verzeichnisstruktur</h2><pre class="filetree">easyit-enterprise-RC1.0.3-dev/
├── index.php              SPOE
├── setup.php              Browser-Tutorial
├── health.php             lokale Systemprüfung
├── documentation.php      Projektdokumentation
├── system/ui/             gemeinsames Seitenlayout
├── assets/css/            Enterprise-Oberfläche
├── DataForm5-Core/        vorhandener Framework-Kern
├── products/              künftige Produkte
├── modules/               produktübergreifende Module
├── storage/               Laufzeitdaten
├── workspace/             Build- und temporäre Daten
└── packages/              Paketablage</pre>
<h2>Dokumente</h2><ul><li><a href="README.md">README.md</a></li><li><a href="INSTALL.md">INSTALL.md</a></li><li><a href="CHANGELOG.md">CHANGELOG.md</a></li><li><a href="ROADMAP.md">ROADMAP.md</a></li><li><a href="CONTRIBUTING.md">CONTRIBUTING.md</a></li><li><a href="LICENSE.md">LICENSE.md</a></li></ul>
<h2>Architekturregel</h2><p>Gemeinsame technische Funktionen gehören in den Enterprise Core. Produktspezifische Funktionen gehören in <code>products/&lt;produkt&gt;</code>. Wiederverwendbare Erweiterungen gehören in <code>modules/</code>.</p>
<?php $content=ob_get_clean();
render_page(['title'=>'Dokumentation','active'=>'docs','content'=>$content,'help'=>['title'=>'Dokumentation','short'=>'Diese Seite erklärt den Zweck der wichtigsten Dateien und Ordner.','steps'=>['Zuerst die Verzeichnisstruktur ansehen.','Danach INSTALL.md durcharbeiten.','Änderungen im CHANGELOG dokumentieren.'],'examples'=>['products/dataform','products/dialog','modules/licensing'],'tips'=>['Der DataForm5-Core bleibt in diesem Build unverändert.']]]);
