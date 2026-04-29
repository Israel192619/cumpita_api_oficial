<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Recuperar contraseña</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px;">

    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                
                <table width="500" style="background: white; padding: 30px; border-radius: 8px;">
                    
                    <tr>
                        <td align="center">
                            <h2 style="color: #333;">Recuperación de contraseña</h2>
                        </td>
                    </tr>

                    <tr>
                        <td>
                            <p style="color: #555;">
                                Hemos recibido una solicitud para restablecer tu contraseña.
                            </p>

                            <p style="color: #555;">
                                Haz clic en el botón de abajo:
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td align="center" style="padding: 20px;">
                            <a href="{{ $link }}" 
                               style="background: #3490dc; color: white; padding: 12px 20px; text-decoration: none; border-radius: 5px;">
                                Restablecer contraseña
                            </a>
                        </td>
                    </tr>

                    <tr>
                        <td>
                            <p style="color: #999; font-size: 12px;">
                                Si no solicitaste este cambio, puedes ignorar este mensaje.
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>