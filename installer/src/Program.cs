using System;
using System.Threading;
using System.Windows.Forms;

namespace Reg
{
    internal static class Program
    {
        // ใช้ Mutex เพื่อให้รันได้ Instance เดียว (ป้องกัน Port 8888 ชนกัน)
        static Mutex mutex = new Mutex(true, "{Reg-SmartCard-Unique-ID}");

        [STAThread]
        static void Main()
        {
            if (mutex.WaitOne(TimeSpan.Zero, true))
            {
                Application.EnableVisualStyles();
                Application.SetCompatibleTextRenderingDefault(false);
                Form1 frm = new Form1();
                Application.Run(frm);
                mutex.ReleaseMutex();
            }
            else
            {
                // ถ้าโปรแกรมเปิดอยู่แล้ว (Mutex ถูกถืออยู่) ให้จบการทำงาน
                return;
            }
        }
    }
}