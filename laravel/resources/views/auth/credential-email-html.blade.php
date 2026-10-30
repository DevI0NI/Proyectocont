<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tu acceso a PROPORTSA</title></head>
<body style="margin:0;padding:0;background-color:#eef3f5;color:#20333b;font-family:Arial,Helvetica,sans-serif;letter-spacing:0;">
    <div style="display:none;max-height:0;overflow:hidden;">Tu clave temporal de PROPORTSA vence en {{ $minutes }} minutos. No la compartas.</div>
    <table role="presentation" style="width:100%;border-collapse:collapse;background-color:#eef3f5;">
        <tr><td align="center" style="padding:32px 16px;">
            <table role="presentation" style="width:100%;max-width:580px;border-collapse:separate;border-spacing:0;background-color:#ffffff;border:1px solid #dce6e9;border-top:5px solid #008577;border-radius:8px;">
                <tr><td style="padding:28px 28px 24px;border-bottom:1px solid #e4edef;">
                    <table role="presentation" style="border-collapse:collapse;"><tr>
                        <td style="width:88px;padding-right:16px;"><img src="{{ $message->embed(public_path('imagenes/IPSA.png')) }}" alt="IPSA" width="88" height="57" style="display:block;border:0;object-fit:contain;"></td>
                        <td><strong style="font-size:21px;color:#00695c;">PROPORTSA</strong><br><span style="font-size:12px;color:#637782;line-height:22px;">Sistema de control portuario</span></td>
                    </tr></table>
                </td></tr>
                <tr><td style="padding:28px;">
                    <h1 style="margin:0 0 14px;font-size:25px;line-height:32px;color:#20333b;">Tu clave temporal</h1>
                    <p style="margin:0 0 24px;font-size:15px;line-height:24px;">Ya puedes definir una nueva contraseña para tu cuenta de PROPORTSA.</p>
                    <table role="presentation" style="width:100%;border-collapse:collapse;background-color:#edf8f5;border:1px solid #cce6df;border-radius:6px;">
                        <tr><td align="center" style="padding:20px 12px;">
                            <p style="margin:0 0 10px;color:#52716b;font-size:12px;">CLAVE DE UN SOLO USO</p>
                            <div style="font-family:Consolas,'Courier New',monospace;font-size:20px;font-weight:700;line-height:30px;color:#00695c;overflow-wrap:anywhere;word-break:break-all;">{{ $temporaryCode }}</div>
                            <p style="margin:12px 0 0;font-size:13px;color:#52716b;">Válida durante {{ $minutes }} minutos</p>
                        </td></tr>
                    </table>
                    <h2 style="margin:26px 0 14px;font-size:16px;">Cómo utilizarla</h2>
                    <p style="margin:0 0 10px;font-size:14px;line-height:23px;"><strong style="color:#008577;">1.</strong> Abre el acceso habitual de PROPORTSA.</p>
                    <p style="margin:0 0 10px;font-size:14px;line-height:23px;"><strong style="color:#008577;">2.</strong> Ingresa tu correo y usa esta clave como contraseña.</p>
                    <p style="margin:0;font-size:14px;line-height:23px;"><strong style="color:#008577;">3.</strong> Define tu nueva contraseña para completar el proceso.</p>
                </td></tr>
                <tr><td style="padding:22px 28px;background-color:#f6f8fa;border-top:1px solid #e4edef;border-radius:0 0 8px 8px;">
                    <p style="margin:0 0 8px;font-size:13px;line-height:21px;font-weight:700;color:#20333b;">No compartas esta clave con nadie.</p>
                    <p style="margin:0;font-size:13px;line-height:21px;color:#637782;">Si no solicitaste este acceso, ignora este correo. Tu contraseña actual no ha cambiado.</p>
                </td></tr>
            </table>
            <p style="margin:20px 0 0;font-size:12px;color:#637782;">PROPORTSA · Acceso seguro a tu cuenta</p>
        </td></tr>
    </table>
</body>
</html>
