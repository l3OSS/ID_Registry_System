@echo off
title SmartCard Service Linker
pushd "%~dp0"
color 0b

echo ==============================================
echo    SMARTCARD SERVICE - DYNAMIC LINKER
echo ==============================================

:: 1. ตรวจสอบสิทธิ์ Admin
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [!] ERROR: Please run as Administrator!
    pause
    exit
)

:: 2. ดึง Path ปัจจุบัน (Dynamic Path)
set "CUR_DIR=%~dp0"
set "SAFE_PATH=%CUR_DIR:\=\\%"

echo [+] Detecting Service at: %CUR_DIR%

:: 3. สร้างและลงทะเบียน Registry ทันที (ไม่ต้องมีไฟล์ .reg แยก)
(
echo Windows Registry Editor Version 5.00
echo.
echo [HKEY_CLASSES_ROOT\smartcard]
echo @="URL:smartcard Protocol"
echo "URL Protocol"=""
echo.
echo [HKEY_CLASSES_ROOT\smartcard\shell\open\command]
echo @="\"%SAFE_PATH%Reg.exe\" \"%%1\""
) > "temp_link.reg"

regedit.exe /s "temp_link.reg"
del "temp_link.reg"

echo.
echo ==============================================
echo    SUCCESS: Service linked successfully!
echo ==============================================
echo You can now use "Read Card" on your website.
echo This program is located at: %CUR_DIR%Reg.exe
echo ==============================================
popd
pause