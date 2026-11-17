Option Explicit

Dim shell
Set shell = CreateObject("WScript.Shell")

shell.Run """E:\wamp\bin\php\php8.4.15\php.exe"" ""E:\wamp\apps\greenportal\scripts\task-worker.php"" --limit=20", 0, False

Set shell = Nothing
