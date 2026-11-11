REM  Traccar Provisioning - insertion de QR codes dans LibreOffice Calc
REM
REM  Mode d'emploi :
REM  1. Copiez ce module dans vos macros LibreOffice
REM     (Outils > Macros > Organiser les macros > Basic).
REM  2. Modifiez QR_ENDPOINT et QR_KEY ci-dessous.
REM  3. Colonne A : identifiants des appareils (à partir de la ligne 2).
REM  4. Colonne B : les images QR seront insérées à partir de la ligne 2.
REM  5. Colonne C : l'URL de configuration sera écrite à partir de la ligne 2.
REM  6. Exécutez InsertQrCodes.
REM
REM  L'endpoint /traccar/qr doit être activé dans les paramètres du module
REM  Drupal (clé API QR non vide). Utilisez HTTPS.

Option Explicit

' URL de base de l'endpoint QR Drupal, sans paramètres.
Const QR_ENDPOINT As String = "https://votre-site.fr/traccar/qr"
' Clé API QR configurée dans les paramètres du module.
Const QR_KEY As String = "VOTRE_CLE_API_QR"

' Feuille de calcul. Laissez vide pour utiliser la feuille active.
Const SHEET_NAME As String = ""
' Colonnes (0 = A, 1 = B, 2 = C, ...).
Const ID_COLUMN As Long = 0
Const QR_COLUMN As Long = 1
Const URL_COLUMN As Long = 2
' Première ligne de données (0 = ligne 1).
Const START_ROW As Long = 1
' Taille demandée au serveur, en pixels.
Const QR_SIZE_PX As Long = 320
' Taille d'affichage dans Calc, en centièmes de millimètre (3000 = 3 cm).
Const QR_DISPLAY_SIZE As Long = 3000

Sub InsertQrCodes
    Dim oDoc As Object
    Dim oSheet As Object
    Dim oCell As Object
    Dim oProvider As Object
    Dim oGraphic As Object
    Dim oShape As Object
    Dim oDrawPage As Object
    Dim oFunction As Object
    Dim oCursor As Object
    Dim args(0) As New com.sun.star.beans.PropertyValue
    Dim i As Long, lastRow As Long
    Dim sId As String, sUrl As String
    Dim sEncodedId As String, sEncodedKey As String
    Dim nAdded As Long

    oDoc = ThisComponent
    If SHEET_NAME <> "" Then
        oSheet = oDoc.Sheets.getByName(SHEET_NAME)
    Else
        oSheet = oDoc.CurrentController.ActiveSheet
    End If

    oDrawPage = oSheet.DrawPage
    oProvider = CreateUnoService("com.sun.star.graphic.GraphicProvider")
    oFunction = CreateUnoService("com.sun.star.sheet.FunctionAccess")

    ' Dernière ligne utilisée dans la feuille.
    oCursor = oSheet.createCursor()
    oCursor.gotoEndOfUsedArea(False)
    lastRow = oCursor.RangeAddress.EndRow

    oDoc.addActionLock()
    nAdded = 0

    For i = START_ROW To lastRow
        sId = Trim(oSheet.getCellByPosition(ID_COLUMN, i).getString())
        If sId <> "" Then
            ' Supprime une éventuelle image précédente portant le même nom.
            RemoveQrShape(oDrawPage, "QR_" & sId)

            sEncodedId = oFunction.callFunction("ENCODEURL", Array(sId))
            sEncodedKey = oFunction.callFunction("ENCODEURL", Array(QR_KEY))
            sUrl = QR_ENDPOINT & "?id=" & sEncodedId & "&key=" & sEncodedKey & "&format=png&size=" & QR_SIZE_PX

            args(0).Name = "URL"
            args(0).Value = sUrl
            On Error Resume Next
            oGraphic = oProvider.queryGraphic(args)
            If Err <> 0 Then
                oGraphic = Null
                Err = 0
            End If
            On Error Goto 0

            If IsNull(oGraphic) Then
                oSheet.getCellByPosition(QR_COLUMN, i).setString("Erreur QR")
            Else
                oShape = oDoc.createInstance("com.sun.star.drawing.GraphicObjectShape")
                oShape.Graphic = oGraphic
                oShape.Name = "QR_" & sId
                oCell = oSheet.getCellByPosition(QR_COLUMN, i)

                oShape.Position = oCell.Position
                oShape.Size = New com.sun.star.awt.Size(QR_DISPLAY_SIZE, QR_DISPLAY_SIZE)
                oDrawPage.add(oShape)

                ' Ancre l'image à la cellule. Selon la version de LibreOffice,
                ' cette propriété peut ne pas être disponible : l'image reste
                ' alors positionnée correctement mais ancrée à la page.
                On Error Resume Next
                oShape.Anchor = oCell
                On Error Goto 0

                oSheet.getCellByPosition(URL_COLUMN, i).setString(sUrl)
                nAdded = nAdded + 1
            End If
        End If
    Next i

    oDoc.removeActionLock()
    MsgBox nAdded & " QR code(s) inséré(s).", 64, "Traccar Provisioning"
End Sub

Sub ClearQrCodes
    Dim oDoc As Object
    Dim oSheet As Object
    Dim oDrawPage As Object
    Dim i As Long

    oDoc = ThisComponent
    If SHEET_NAME <> "" Then
        oSheet = oDoc.Sheets.getByName(SHEET_NAME)
    Else
        oSheet = oDoc.CurrentController.ActiveSheet
    End If
    oDrawPage = oSheet.DrawPage

    For i = oDrawPage.getCount() - 1 To 0 Step -1
        If Left(oDrawPage.getByIndex(i).Name, 3) = "QR_" Then
            oDrawPage.remove(oDrawPage.getByIndex(i))
        End If
    Next i

    MsgBox "Images QR supprimées.", 64, "Traccar Provisioning"
End Sub

Private Sub RemoveQrShape(oDrawPage As Object, sName As String)
    Dim i As Long
    For i = oDrawPage.getCount() - 1 To 0 Step -1
        If oDrawPage.getByIndex(i).Name = sName Then
            oDrawPage.remove(oDrawPage.getByIndex(i))
        End If
    Next i
End Sub
