@echo off
title SmartCard Service Installer
color 0b

echo ==============================================
echo    CITIZEN REGISTRATION - STAFF TOOLS SETUP
echo ==============================================
echo.

:: 1. ตรวจสอบสิทธิ์ Admin
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [!] Please run this file as Administrator! (คลิกขวาแล้วเลือก Run as Administrator)
    pause
    exit
)

:: 2. สร้างโฟลเดอร์ปลายทาง
echo [+] Creating directory at C:\SmartCard...
if not exist "C:\SmartCard" mkdir "C:\SmartCard"

:: 3. คัดลอกโปรแกรม .exe
echo [+] Copying SmartCardService.exe...
copy /Y "SmartCardService.exe" "C:\SmartCard\"

:: 4. ลงทะเบียน Registry แบบเงียบ (Silent)
echo [+] Registering SmartCard Protocol...
regedit.exe /s "register_protocol.reg"

:: 5. สร้าง Shortcut ไว้ที่หน้า Desktop (Optional)
echo [+] Creating Shortcut on Desktop...
set SCRIPT="%TEMP%\%RANDOM%-%RANDOM%-%RANDOM%-%RANDOM%.vbs"
echo Set oWS = WScript.CreateObject("WScript.Shell") >> %SCRIPT%
echo sLinkFile = oWS.ExpandEnvironmentStrings("%%USERPROFILE%%\Desktop\SmartCard Service.lnk") >> %SCRIPT%
echo Set oLink = oWS.CreateShortcut(sLinkFile) >> %SCRIPT%
echo oLink.TargetPath = "C:\SmartCard\SmartCardService.exe" >> %SCRIPT%
echo oLink.Save >> %SCRIPT%
cscript /nologo %SCRIPT%
del %SCRIPT%

echo.
echo ==============================================
echo    SUCCESS: Installation Completed!
echo ==============================================
echo [Note] 1. Open 'SmartCard Service' on your desktop.
echo        2. Now you can use "Read Card" on the website.
echo ==============================================
pause