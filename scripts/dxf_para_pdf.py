# -*- coding: utf-8 -*-
"""
Desenho DXF -> PDF para o visualizador de arquivos do GreenPortal.
Chamado só pelo serviço agendado scripts/visualizador-worker.php (Agendador do Windows), nunca pela tela.
O DWG chega aqui já convertido em DXF pelo ODA File Converter.

Uso: python dxf_para_pdf.py entrada.dxf saida.pdf
Requer (Python 3.7):  python -m pip install ezdxf==1.0.3 matplotlib==3.5.3

Páginas do PDF: 1ª = espaço do modelo (o desenho completo); depois, uma página por folha (layout de papel) que tenha
conteúdo. Fundo branco, linhas escuras, texto pesquisável. Arquivos com erro de estrutura são abertos em modo
de recuperação (ezdxf.recover). Códigos de saída: 0 ok, 2 uso, 3 sem bibliotecas, 4 desenho vazio, 5 erro de leitura.
"""
import os
import sys


def sair(codigo, mensagem):
    sys.stderr.write(mensagem + "\n")
    sys.exit(codigo)


SOLIDOS = {"3DSOLID", "BODY", "REGION", "SURFACE", "EXTRUDEDSURFACE", "LOFTEDSURFACE", "REVOLVEDSURFACE", "SWEPTSURFACE",
           "PLANESURFACE", "NURBSURFACE"}
ENTIDADES_3D = SOLIDOS | {"MESH", "POLYFACE", "3DFACE"}

# Vistas do modelo 3D: (título, direção de onde se olha, direita da tela, cima da tela)
VISTAS = [
    ("Isométrica", (1.0, -1.0, 1.0), (1.0, 1.0, 0.0), None),
    ("Planta (vista superior)", (0.0, 0.0, 1.0), (1.0, 0.0, 0.0), (0.0, 1.0, 0.0)),
    ("Vista frontal", (0.0, -1.0, 0.0), (1.0, 0.0, 0.0), (0.0, 0.0, 1.0)),
    ("Vista lateral direita", (1.0, 0.0, 0.0), (0.0, 1.0, 0.0), (0.0, 0.0, 1.0)),
]


def _norm(v):
    m = (v[0] ** 2 + v[1] ** 2 + v[2] ** 2) ** 0.5 or 1.0
    return (v[0] / m, v[1] / m, v[2] / m)


def _cross(a, b):
    return (a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0])


def _dot(a, b):
    return a[0] * b[0] + a[1] * b[1] + a[2] * b[2]


def faces_3d(layout):
    """Faces (listas de pontos 3D) dos sólidos ACIS (via ezdxf.acis), MESH, POLYFACE e 3DFACE do layout."""
    faces, falhas = [], 0
    try:
        from ezdxf.acis import api as acis
    except Exception:  # noqa: BLE001
        acis = None
    for e in layout:
        t = e.dxftype()
        try:
            if t in SOLIDOS:
                if acis is None:
                    falhas += 1
                    continue
                for corpo in acis.load_dxf(e):
                    for malha in acis.mesh_from_body(corpo):
                        for f in malha.faces:
                            faces.append([tuple(malha.vertices[i]) for i in f])
            elif t == "MESH":
                vs = [tuple(v) for v in e.vertices]
                for f in e.faces:
                    faces.append([vs[i] for i in f])
            elif t == "3DFACE":
                faces.append([tuple(v) for v in e.wcs_vertices()])
            elif t == "POLYLINE" and e.is_poly_face_mesh:
                for f in e.faces():
                    faces.append([tuple(v.dxf.location) for v in f])
        except Exception:  # noqa: BLE001
            falhas += 1
    return [f for f in faces if len(f) >= 3], falhas


def pagina_vistas(plt, faces, titulo):
    """Folha A3 com isométrica sombreada e planta/frontal/lateral do modelo 3D (sólidos não têm desenho 2D)."""
    from matplotlib.collections import PolyCollection
    fig = plt.figure(figsize=(16.54, 11.69))
    fig.suptitle(titulo, fontsize=11, color="#333333", y=0.985)
    excesso = len(faces) > 150000
    for n, (nome, olho, direita, cima) in enumerate(VISTAS):
        d = _norm(olho)
        r = _norm(direita)
        u = _norm(cima) if cima else _norm(_cross(d, r))
        luz = _norm((d[0] + 0.3, d[1] + 0.2, d[2] + 0.5))
        polys, cores, prof = [], [], []
        for f in faces:
            p2 = [(_dot(v, r), _dot(v, u)) for v in f]
            # normal pela fórmula de Newell
            nx = ny = nz = 0.0
            for i in range(len(f)):
                a, b = f[i], f[(i + 1) % len(f)]
                nx += (a[1] - b[1]) * (a[2] + b[2])
                ny += (a[2] - b[2]) * (a[0] + b[0])
                nz += (a[0] - b[0]) * (a[1] + b[1])
            k = abs(_dot(_norm((nx, ny, nz)), luz))
            c = 0.55 + 0.4 * k
            polys.append(p2)
            cores.append((c * 0.86, c * 0.9, c * 0.95))
            prof.append(sum(_dot(v, d) for v in f) / len(f))
        ordem = sorted(range(len(polys)), key=lambda i: prof[i])   # do mais distante para o mais próximo
        ax = fig.add_subplot(2, 2, n + 1)
        ax.set_title(nome, fontsize=9, color="#555555")
        ax.set_axis_off()
        col = PolyCollection([polys[i] for i in ordem], facecolors="none" if excesso else [cores[i] for i in ordem],
                             edgecolors="#2b2b2b", linewidths=0.25)
        ax.add_collection(col)
        xs = [p[0] for pl in polys for p in pl]
        ys = [p[1] for pl in polys for p in pl]
        mx = (max(xs) - min(xs)) * 0.04 or 1
        my = (max(ys) - min(ys)) * 0.04 or 1
        ax.set_xlim(min(xs) - mx, max(xs) + mx)
        ax.set_ylim(min(ys) - my, max(ys) + my)
        ax.set_aspect("equal", adjustable="box")
    fig.subplots_adjust(left=0.02, right=0.98, bottom=0.03, top=0.95, wspace=0.05, hspace=0.1)
    return fig


def diagnostico(doc, auditor):
    """Resumo do desenho (usado por visualizador-worker.php --testar) para entender prévias vazias."""
    import json
    from collections import Counter
    try:
        from ezdxf import bbox
    except Exception:  # noqa: BLE001
        bbox = None
    info = {"versao_dxf": doc.dxfversion, "erros_auditoria": len(getattr(auditor, "errors", [])),
            "extmin": list(doc.header.get("$EXTMIN", (0, 0, 0))), "extmax": list(doc.header.get("$EXTMAX", (0, 0, 0))),
            "insunits": doc.header.get("$INSUNITS", 0), "layouts": [], "blocos_xref": []}
    for blk in doc.blocks:
        try:
            if blk.block.is_xref:
                info["blocos_xref"].append(blk.name + " -> " + str(blk.block.dxf.get("xref_path", "")))
        except Exception:  # noqa: BLE001
            pass
    for nome in doc.layout_names_in_taborder():
        lay = doc.layouts.get(nome)
        tipos = Counter(e.dxftype() for e in lay)
        item = {"nome": nome, "entidades": sum(tipos.values()), "tipos": dict(tipos.most_common(15))}
        inserts = Counter(e.dxf.name for e in lay if e.dxftype() == "INSERT")
        if inserts:
            item["blocos_inseridos"] = dict(inserts.most_common(10))
        cores = Counter((e.dxf.get("color", 256), e.dxf.get("true_color", None)) for e in lay if hasattr(e, "dxf"))
        item["cores"] = {str(k): v for k, v in cores.most_common(6)}
        camadas_off = [l.dxf.name for l in doc.layers if (l.is_off() or l.is_frozen())]
        item["camadas_desligadas"] = camadas_off[:20]
        if bbox is not None and sum(tipos.values()) <= 200000:
            try:
                ext = bbox.extents(lay, fast=True)
                if ext.has_data:
                    item["extensao"] = [list(ext.extmin), list(ext.extmax)]
            except Exception as erro:  # noqa: BLE001
                item["extensao_erro"] = str(erro)
        info["layouts"].append(item)
    print("DIAG " + json.dumps(info, ensure_ascii=False, default=str))


def main():
    if len(sys.argv) != 3:
        sair(2, "uso: dxf_para_pdf.py entrada.dxf saida.pdf")
    entrada, saida = sys.argv[1], sys.argv[2]
    os.environ.setdefault("MPLBACKEND", "Agg")
    try:
        import matplotlib
        matplotlib.use("Agg")
        matplotlib.rcParams["pdf.fonttype"] = 42      # texto como texto (pesquisável), não como curvas
        matplotlib.rcParams["savefig.facecolor"] = "white"
        import matplotlib.pyplot as plt
        from matplotlib.backends.backend_pdf import PdfPages
        import ezdxf
        from ezdxf import recover
        from ezdxf.addons.drawing import RenderContext, Frontend
        from ezdxf.addons.drawing.matplotlib import MatplotlibBackend
    except Exception as erro:  # noqa: BLE001
        sair(3, "Bibliotecas Python ausentes (ezdxf/matplotlib): %s. Instale: python -m pip install ezdxf==1.0.3 matplotlib==3.5.3" % erro)
    try:
        from ezdxf.addons.drawing.properties import LayoutProperties
    except Exception:  # noqa: BLE001
        LayoutProperties = None

    try:
        doc, auditor = recover.readfile(entrada)
    except IOError as erro:
        sair(5, "Não foi possível ler o DXF: %s" % erro)
    except ezdxf.DXFStructureError as erro:
        sair(5, "DXF inválido ou corrompido: %s" % erro)

    if os.environ.get("GP_DXF_DIAG"):
        diagnostico(doc, auditor)

    layouts = [doc.modelspace()]
    for nome in doc.layout_names_in_taborder():
        if nome.lower() == "model":
            continue
        try:
            folha = doc.layouts.get(nome)
        except Exception:  # noqa: BLE001
            continue
        # Folha só com a viewport padrão não acrescenta nada
        if sum(1 for e in folha if e.dxftype() != "VIEWPORT") > 0:
            layouts.append(folha)

    paginas = 0
    with PdfPages(saida) as pdf:
        for layout in layouts:
            if len(layout) == 0:
                continue
            tipos = set(e.dxftype() for e in layout)
            tem_3d = bool(tipos & ENTIDADES_3D) or any(e.dxftype() == "POLYLINE" and e.is_poly_face_mesh for e in layout)
            if tem_3d:
                faces, falhas = faces_3d(layout)
                if faces:
                    fig = pagina_vistas(plt, faces, "Modelo 3D - vistas geradas pelo sistema (%s)" % layout.name)
                    fig.text(0.995, 0.003, "Prévia gerada pelo GreenPortal - %s - %d faces%s" % (layout.name, len(faces),
                             (" - %d sólidos sem prévia (superfícies curvas)" % falhas) if falhas else ""),
                             fontsize=6, color="#888888", ha="right", va="bottom")
                    pdf.savefig(fig, facecolor="white")
                    plt.close(fig)
                    paginas += 1
                elif falhas:
                    sys.stderr.write("Layout %s: %d sólidos 3D sem prévia\n" % (layout.name, falhas))
                # Só 3D (e viewports): a página 2D sairia em branco
                if not (tipos - ENTIDADES_3D - {"VIEWPORT", "POLYLINE"}):
                    continue
            fig = plt.figure(figsize=(16.54, 11.69))  # A3 paisagem; ajustado abaixo
            ax = fig.add_axes([0.01, 0.01, 0.98, 0.98])
            ax.set_axis_off()
            ctx = RenderContext(doc)
            ctx.set_current_layout(layout)
            props = None
            if LayoutProperties is not None:
                try:
                    props = LayoutProperties.from_layout(layout)
                    props.set_colors("#ffffff")  # fundo branco: a cor "7" (branco/preto) sai preta
                except Exception:  # noqa: BLE001
                    props = None
            try:
                try:
                    backend = MatplotlibBackend(ax, adjust_figure=False)  # o tamanho da folha é decidido aqui (A3)
                except TypeError:
                    backend = MatplotlibBackend(ax)
                frontend = Frontend(ctx, backend)
                if props is not None:
                    frontend.draw_layout(layout, finalize=True, layout_properties=props)
                else:
                    frontend.draw_layout(layout, finalize=True)
            except Exception as erro:  # noqa: BLE001
                plt.close(fig)
                sys.stderr.write("Layout %s ignorado: %s\n" % (layout.name, erro))
                continue
            x0, x1 = ax.get_xlim()
            y0, y1 = ax.get_ylim()
            if abs(y1 - y0) > abs(x1 - x0) * 1.05:
                fig.set_size_inches(11.69, 16.54)  # A3 retrato
            else:
                fig.set_size_inches(16.54, 11.69)  # A3 paisagem
            ax.set_aspect("equal", adjustable="box")
            fig.text(0.995, 0.003, "Prévia gerada pelo GreenPortal - %s" % layout.name, fontsize=6, color="#888888", ha="right", va="bottom")
            pdf.savefig(fig, facecolor="white")
            plt.close(fig)
            paginas += 1
    if paginas == 0:
        try:
            os.remove(saida)
        except OSError:
            pass
        sair(4, "O desenho não tem entidades para mostrar.")
    print("ok %d pagina(s)" % paginas)


if __name__ == "__main__":
    main()
