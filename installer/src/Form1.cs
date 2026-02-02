using System;
using System.Drawing;
using System.Drawing.Imaging;
using System.Text;
using System.Windows.Forms;
using System.Net;
using System.Threading.Tasks;
using System.Globalization;
using System.IO;
using ThaiNationalIDCard;

namespace Reg
{
    public partial class Form1 : Form
    {
        private ThaiIDCard idCard;
        private HttpListener listener;
        private bool isServerRunning = false;
        private bool isReading = false;

        // ตัวนับเวลาสำหรับปิดโปรแกรมอัตโนมัติ
        private int idleCounter = 0;
        private const int MAX_IDLE_TIME = 300; // 5 นาที
        private System.Windows.Forms.Timer autoCloseTimer;

        // ส่วนประกอบสำหรับการทำงานเบื้องหลัง (Background Mode)
        private NotifyIcon notifyIcon;
        private ContextMenuStrip contextMenu;

        public Form1()
        {
            Encoding.RegisterProvider(CodePagesEncodingProvider.Instance);
            InitializeComponent();
            SetupBackgroundMode(); // ตั้งค่า Tray Icon
        }

        private void SetupBackgroundMode()
        {
            // สร้างเมนูคลิกขวาที่ไอคอน
            contextMenu = new ContextMenuStrip();
            contextMenu.Items.Add("เปิดหน้าจอ Log", null, (s, e) => { this.Show(); this.WindowState = FormWindowState.Normal; });
            contextMenu.Items.Add("-");
            contextMenu.Items.Add("Exit (ปิดโปรแกรม)", null, (s, e) => {
                isServerRunning = false;
                Application.Exit();
            });

            // สร้างตัวไอคอนที่ Tray (มุมขวาล่าง)
            notifyIcon = new NotifyIcon();
            notifyIcon.Icon = SystemIcons.Shield; // คุณสามารถเปลี่ยนเป็นไฟล์ .ico ของคุณเองได้
            notifyIcon.ContextMenuStrip = contextMenu;
            notifyIcon.Text = "Citizen Registration Service (Running)";
            notifyIcon.Visible = true;

            // ดับเบิลคลิกที่ไอคอนเพื่อเปิดหน้าจอ
            notifyIcon.DoubleClick += (s, e) => { this.Show(); this.WindowState = FormWindowState.Normal; };

            // ตั้งค่าเริ่มต้นให้ซ่อนหน้าจอ
            this.WindowState = FormWindowState.Minimized;
            this.ShowInTaskbar = false;
        }

        protected override void OnLoad(EventArgs e)
        {
            base.OnLoad(e);
            this.Hide(); // ซ่อนหน้าต่างทันทีที่รัน
            StartWebServer();
            InitializeAutoCloseTimer();
        }

        private void InitializeAutoCloseTimer()
        {
            autoCloseTimer = new System.Windows.Forms.Timer();
            autoCloseTimer.Interval = 1000;
            autoCloseTimer.Tick += (s, e) => {
                idleCounter++;
                if (idleCounter >= MAX_IDLE_TIME)
                {
                    autoCloseTimer.Stop();
                    notifyIcon.Visible = false; // เอาไอคอนออกก่อนปิด
                    Application.Exit();
                }
            };
            autoCloseTimer.Start();
        }

        private void StartWebServer()
        {
            Task.Run(() =>
            {
                try
                {
                    listener = new HttpListener();
                    listener.Prefixes.Add("http://localhost:8888/read/");
                    listener.Start();
                    isServerRunning = true;
                    UpdateStatus("Service Standby: http://localhost:8888/read/", Color.Blue);

                    while (isServerRunning)
                    {
                        var context = listener.GetContext();
                        Task.Run(() => ProcessRequest(context));
                    }
                }
                catch (Exception ex)
                {
                    UpdateStatus("Server Error: " + ex.Message, Color.Red);
                }
            });
        }

        private void ProcessRequest(HttpListenerContext context)
        {
            idleCounter = 0; // Reset เวลาเมื่อมีการใช้งาน

            using (var response = context.Response)
            {
                response.AppendHeader("Access-Control-Allow-Origin", "*");
                response.AppendHeader("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
                response.AppendHeader("Content-Type", "application/json; charset=utf-8");

                if (context.Request.HttpMethod == "OPTIONS") { response.StatusCode = 200; return; }

                if (isReading)
                {
                    SendJsonResponse(response, "{\"error\": \"Busy: กำลังอ่านบัตรลำดับก่อนหน้า...\"}", HttpStatusCode.Conflict);
                    return;
                }

                isReading = true;
                string jsonResponse = "{}";

                try
                {
                    UpdateStatus("Reading Smart Card...", Color.Orange);
                    idCard = new ThaiIDCard();
                    Personal personal = idCard.readAllPhoto();

                    if (personal != null)
                    {
                        string photoBase64 = "";
                        if (personal.PhotoBitmap != null)
                        {
                            using (Bitmap bmpCopy = new Bitmap(personal.PhotoBitmap))
                            using (MemoryStream ms = new MemoryStream())
                            {
                                bmpCopy.Save(ms, ImageFormat.Jpeg);
                                photoBase64 = Convert.ToBase64String(ms.ToArray());
                            }
                            personal.PhotoBitmap.Dispose();
                        }

                        var data = new
                        {
                            CitizenID = personal.Citizenid,
                            Prefix = personal.Th_Prefix,
                            Firstname = personal.Th_Firstname,
                            Lastname = personal.Th_Lastname,
                            Gender = personal.Sex,
                            BirthDate = personal.Birthday.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture),
                            HouseNo = personal.addrHouseNo,
                            Moo = personal.addrVillageNo,
                            Tambon = personal.addrTambol,
                            Amphoe = personal.addrAmphur,
                            Province = personal.addrProvince,
                            Photo = photoBase64
                        };

                        jsonResponse = System.Text.Json.JsonSerializer.Serialize(data);
                        UpdateStatus($"Read Success: {personal.Th_Firstname}", Color.Green);

                        // แสดง Balloon Tip แจ้งเตือนที่มุมจอเมื่ออ่านสำเร็จ
                        notifyIcon.ShowBalloonTip(3000, "อ่านข้อมูลสำเร็จ", $"คุณ {personal.Th_Firstname} ถูกดึงข้อมูลแล้ว", ToolTipIcon.Info);
                    }
                    else
                    {
                        jsonResponse = "{\"error\": \"ไม่พบข้อมูลบัตร\"}";
                        UpdateStatus("Error: Card not found", Color.Red);
                    }
                }
                catch (Exception ex)
                {
                    jsonResponse = "{\"error\": \"System Error: " + ex.Message + "\"}";
                    UpdateStatus("System Error: " + ex.Message, Color.Red);
                }
                finally
                {
                    isReading = false;
                    GC.Collect();
                }

                SendJsonResponse(response, jsonResponse);
            }
        }

        private void SendJsonResponse(HttpListenerResponse response, string json, HttpStatusCode status = HttpStatusCode.OK)
        {
            try
            {
                response.StatusCode = (int)status;
                byte[] buffer = Encoding.UTF8.GetBytes(json);
                response.ContentLength64 = buffer.Length;
                response.OutputStream.Write(buffer, 0, buffer.Length);
            }
            catch { }
        }

        private void UpdateStatus(string msg, Color color)
        {
            if (this.IsDisposed) return;
            if (lblStatus.InvokeRequired)
            {
                lblStatus.Invoke(new Action(() => UpdateStatus(msg, color)));
                return;
            }
            lblStatus.Text = msg;
            lblStatus.ForeColor = color;
            txtLog.AppendText($"{DateTime.Now:HH:mm:ss}: {msg}\r\n");
            if (txtLog.TextLength > 5000) txtLog.Clear();
            txtLog.ScrollToCaret();
        }

        private void Form1_FormClosing(object sender, FormClosingEventArgs e)
        {
            // ถ้าเจ้าหน้าที่กดกากบาท (X) ให้แค่ซ่อนลงไปที่ Tray ไม่ต้องปิดโปรแกรมจริงๆ
            if (e.CloseReason == CloseReason.UserClosing)
            {
                e.Cancel = true;
                this.Hide();
                notifyIcon.ShowBalloonTip(2000, "Service is still running", "โปรแกรมยังทำงานอยู่ที่นี่ครับ", ToolTipIcon.Info);
            }
        }
    }
}