
<?php

require __DIR__ . '/../src/Releaser/Releaser.php';

/*$releaser = new \Releaser\Releaser('20fb65bfce6a96c11981680bfaf727f574cae567', 'discovery-fusion');
$releaser->release('fusion-site-it-dplay-com', ['fusion'], ['video', 'brightcove-player', 'wordpress', 'taxonomies'], 'minor', 'master', 'sandbox');*/

$releaser = new \Releaser\Releaser('20fb65bfce6a96c11981680bfaf727f574cae567', 'gundars');
$releaser->release('hoglog', [], [], 'minor', 'master', 'interactive');
