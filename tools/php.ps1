# Runs PHP with the project's isolated configuration.
$env:PHPRC = Join-Path $PSScriptRoot '../.runtime/php.ini'
& php @args
exit $LASTEXITCODE

