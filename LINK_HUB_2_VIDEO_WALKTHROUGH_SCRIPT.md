# Link Hub 2.0 — Video Hướng Dẫn Quản Trị (Tiếng Việt)

**Cách sử dụng:** đọc từng phần theo nhịp thông thường trong khi ghi hình màn hình tại `https://bakudanramen.com/links-admin/`. Mỗi phần ghi rõ: thao tác click nào, nói gì, và chỉ ra điểm gì. Các phần đánh dấu **⚠ CHƯA HOẠT ĐỘNG** cần bỏ qua hoặc nói rõ "đang chuẩn bị, chưa chạy" — không minh họa như thể đang hoạt động.

**Độ dài khuyến nghị:** 15–22 phút nếu đi qua tất cả các phần.

---

## 0. Mở đầu (30 giây)

**Hiển thị:** màn hình đăng nhập tại `/links-admin/`.

**Nói:**
"Đây là trang quản trị Links Hub của Bakudan Ramen. Đây là nơi duy nhất để quản lý mọi thứ khách hàng thấy khi quét mã QR hoặc bấm link trên Instagram bio — các nút Order Online, Rewards, Staff Training, và nhiều hơn nữa. Không cần biết lập trình để sử dụng. Cùng đi qua tất cả các trang nhé."

**Chỉ ra:** nút bật/tắt hiển thị mật khẩu (biểu tượng mắt) trên form đăng nhập — bấm để hiện/ẩn mật khẩu đã gõ trước khi đăng nhập.

---

## 1. Dashboard (1 phút 30 giây)

**Hiển thị:** Dashboard — màn hình đầu tiên sau khi đăng nhập.

**Nói:**
"Sau khi đăng nhập, bạn đến Dashboard. Đây là bản tổng quan nhanh về toàn bộ Link Hub của bạn."

**Chỉ ra lần lượt:**
- Thanh **"Action Required"** màu cam (nếu có) — liệt kê các vấn đề thực: trang còn draft, trang có thay đổi chưa publish, hoặc thiếu thông tin SEO. "Nếu thanh này trống, mọi thứ đều ổn."
- Các ô số: Total Buttons, Live, Hidden, Scheduled, Expired, Featured, Views (24h), Clicks (24h). "Đây là số liệu thực từ traffic trang web, cập nhật live."
- Hàng **Quick Actions** — các phím tắt một click: Manage Pages, View Public site, Project Hub, Scheduling, Create Blog Post, Settings.
- Các thẻ **Pages Overview** bên dưới — mỗi thẻ cho một trang bạn quản lý (Customer Link Hub, Staff Training, ...), hiển thị trạng thái LIVE/HIDDEN và số nút, có nút "Edit Buttons" nhanh.

**Quy tắc quản trị cần nói rõ:** "Cảnh báo trên Dashboard là thật — nếu nó báo trang nào đó thiếu SEO, thì đúng là thiếu ngay lúc này, không phải nhắc nhở chung."

---

## 2. Trang chính — Pages (3–4 phút)

**Hiển thị:** bấm **Pages** trên thanh bên trái.

**Nói:**
"Đây là trung tâm điều khiển cho mọi trang trong Link Hub — hiện tại là Customer Link Hub công khai và trang Staff Training riêng tư, nhưng bạn có thể thêm nhiều loại khác."

**Chỉ ra:**
- Các ô tổng quan: Total Pages, Customer Pages, Staff Pages, Published, Drafts, Scheduled.
- Bảng với các cột: **Page**, **Type**, **Visibility**, **Status**, **Live URL**, **Actions**.
- Badge màu theo loại trang: xanh lá "Customer Link Hub" vs tím "Staff Training" — "Màu sắc này giúp bạn luôn phân biệt được nội dung nào công khai, nào riêng tư."
- Dòng chú thích ở cuối giải thích ý nghĩa từng loại trang.
- Bấm **Edit** trên "Bakudan links Main" để vào trình chỉnh sửa trang.

### 2a. Page Editor — Tab Buttons (nút mặc định)

**Hiển thị:** tab Buttons (tab mặc định khi mở trình chỉnh sửa).

**Nói:**
"Đây là nơi quản lý mọi nút bấm được trên trang này — các link Order Online, Rewards signups, mạng xã hội, bất kỳ thứ gì."

**Chỉ ra:**
- Thanh trạng thái: trạng thái hiện tại (Draft/Published), **Save**, **Publish/Unpublish**, **Verify**, **Save as Template**, và **Back**.
- **"Save as Template"** — "Nếu bạn xây dựng một trang ưng ý, có thể lưu toàn bộ cấu trúc — sections và buttons — thành một template dùng lại, để tạo trang cho địa điểm mới chỉ trong một click thay vì bắt đầu từ đầu." (Sẽ nói kỹ hơn ở phần Templates.)
- Mỗi hàng nút hiển thị: tiêu đề, badge (Featured / tên section / style), URL đích (hoặc "no url" cho nút lấy đích từ Location), công tắc Visible / Enabled / Featured, và các biểu tượng hành động: Edit, Duplicate, Move-to-another-page, Delete.
- Kéo thả để sắp xếp thứ tự nút — hiện thanh "Drag rows to reorder" khi bắt đầu kéo.
- Bấm **Edit** (biểu tượng bút chì) trên bất kỳ nút nào để mở trình chỉnh sửa nút.

### 2b. Button Editor — Các loại Destination

**Nói:**
"Mỗi nút có một Destination Type — điều này cho hệ thống biết đó là loại link gì, và ngăn chặn lỗi classic khi một link thực sự bị viết sai."

**Chỉ ra, scroll qua dropdown Destination Type:**
- **External Website / YouTube Video / Phone Number / Email / Google Maps / PDF / Download File / Toast Online Ordering / Toast Marketing Signup / Instagram / Facebook** — các loại link cổ điển, mỗi loại có gợi ý định dạng đúng (ví dụ: phone hiển thị "+1 210 555 0100").
- **Internal Link Hub Page** — liên kết đến trang khác bạn quản lý, theo tên, không phải gõ URL — "nếu bạn đổi tên URL slug của trang đó, link này vẫn hoạt động tự động."
- **Call This Location / Get Directions / Store Hours / Order Support Email** — loại mới nhất. "Thay vì gõ số điện thoại hoặc địa chỉ bằng tay, bạn chọn một Location từ dropdown, và nút sẽ tự động dùng phone, map link, hours, hoặc support email của địa điểm đó. Nếu bạn cập nhật số điện thoại Stone Oak ở một chỗ, mọi nút 'Call This Location' trỏ đến Stone Oak đều tự cập nhật — không cần tìm và sửa năm nút khác nhau."
- **Heading / Text Block / Image** — các khối nội dung không phải link, dùng để thêm tiêu đề section hoặc đoạn văn bản trực tiếp trên trang.
- Các trường **Section, Icon, Style, Custom SVG Icon** bên dưới — các điều khiển trang trí, không cần code.
- **Start Date/Time và End Date/Time** — để lên lịch nút tự động hiện hoặc ẩn (ví dụ: khuyến mãi có thời hạn).
- Bốn công tắc ở cuối: **Visible, Enabled, Featured, New Tab**.

**Quy tắc quản trị cần nói rõ:** "Không bao giờ dán public URL của trang nội bộ vào trường destination — luôn dùng 'Internal Link Hub Page' để link tồn tại nếu URL thay đổi."

### 2c. Page Editor — Tab Sections

**Hiển thị:** bấm tab **Sections**.

**Nói:**
"Sections là cách nhóm các nút lại — như tiêu đề 'Order Online' hay 'Rewards & Loyalty' trên trang công khai. Bạn có thể tạo, đổi tên, sắp xếp thứ tự, ẩn, lên lịch, hoặc xóa sections ở đây, và mỗi nút bạn thấy đều thuộc đúng một section (hoặc không thuộc section nào)."

### 2d. Page Editor — Tab Page Settings

**Hiển thị:** bấm tab **Page Settings**.

**Nói:**
"Tab này điều khiển những thứ về chính trang đó, không phải nút của nó: tiêu đề, URL slug, trang công khai hay unlisted, và thông tin SEO — title tag, meta description, ảnh preview mạng xã hội, và canonical URL. Nếu Google hiển thị sai tiêu đề cho trang này trong kết quả tìm kiếm, đây là nơi sửa — không cần code."

**Chỉ ra:**
- Hộp preview kiểu Google cập nhật live khi bạn gõ SEO title/description.
- Trường **Page Type** (Link Hub / Staff Training / Marketing Signup / Campaign / Location / Custom) và **Visibility** (Public / Unlisted / Staff Only / Password Protected / Inactive).
- Công tắc **Show on Customer Link Hub** và **Search Engine Indexing**.
- Các trường: Profile Handle, Headline, Tagline.
- Các trường SEO: SEO Title (có bộ đếm ký tự), Meta Description (có bộ đếm), Open Graph image, Canonical URL.

### 2e. Page Editor — Tab Publish & Preview

**Hiển thị:** bấm tab **Publish & Preview**.

**Chỉ ra:**
- Phần **PAGE STATUS**: chọn Draft / Private / Scheduled / Published. Nếu chọn Scheduled, sẽ hiện trường chọn ngày giờ cụ thể để tự động publish.
- Các nút: **Save**, **Publish Now** (hoặc **Unpublish** nếu đã publish).
- Phần **PRIVATE PREVIEW LINK** — tạo link xem trước riêng tư để chia sẻ với team review trước khi publish. Token xem trước có thể regenerate để vô hiệu hóa link cũ.
- Phần **VERSION HISTORY** — bấm Load để xem các phiên bản đã publish trước đó, với nút Preview và Restore.

**Quy tắc quản trị:** "Trước khi publish, hệ thống có thể cảnh báo lỗi — broken links, duplicate buttons, nội dung staff trên trang public. Sửa hoặc xác nhận cố ý trước khi tiếp tục."

---

## 3. Templates (1 phút)

**Hiển thị:** bấm **Templates** trên thanh bên.

**Nói:**
"Sau khi xây dựng một trang ưng ý — ví dụ một trang location chuẩn — bạn có thể lưu nó thành Template ngay từ trình chỉnh sửa trang. Đây là thư viện của mọi template bạn đã lưu."

**Chỉ ra:**
- Bảng: Name, Description, Page Type, ngày lưu, và hai hành động — **Create Page** và Delete.
- Bấm **Create Page** trên một template, hiện modal: "Bạn chỉ cần gõ tiêu đề và URL slug, hệ thống sẽ tạo trang mới với đúng các sections và buttons như bản gốc — hoàn toàn có thể chỉnh sửa, và độc lập với template."

**Quy tắc:** "Đây là cách nhanh nhất để tạo trang cho địa điểm mới — lưu trang location tốt nhất thành template một lần, sau đó create-from-template mỗi khi mở cửa hàng mới."

---

## 4. Campaigns (1–2 phút)

**Hiển thị:** bấm **Campaigns** trên thanh bên.

**Nói:**
"Đây là nơi theo dõi một chiến dịch marketing — khuyến mãi hè, dịp lễ — như một thứ thống nhất, dù nó có thể ảnh hưởng nhiều shortlink và trang."

**Chỉ ra:**
- Nút **Create Campaign** — các trường modal: Campaign Name, Description, Status (draft/active/ended), Associated Page, Start Date, End Date.
- Bảng kết quả: Name, Status badge, linked Page, khoảng ngày, số shortlinks, và tổng Clicks rollup từ tất cả.
- Biểu tượng Edit và Delete trên mỗi dòng.

**Nói khi tạo thử một campaign:** "Tôi sẽ tạo một campaign test — lưu ý nó xuất hiện trong danh sách ngay lập tức, với zero shortlinks và zero clicks vì nó hoàn toàn mới."

**Quy tắc:** "Xóa một campaign không bao giờ làm hỏng shortlinks của nó — chúng chỉ mất nhãn campaign, vẫn hoạt động."

---

## 5. UTM Builder (1 phút)

**Hiển thị:** bấm **UTM Builder** trên thanh bên.

**Nói:**
"Khi bạn đăng một link trên Instagram, Facebook, hoặc tờ rơi in ấn, bạn muốn biết kênh nào thực sự drive clicks. Đó là mục đích của UTM parameters."

**Chỉ ra:**
- Các trường: Destination URL, Source, Medium, Campaign, Content, Term, và Location dropdown.
- Preview URL cập nhật live khi bạn gõ.
- Hai nút: **Copy URL** và **Create Shortlink + QR**.

**Quy tắc:** "Thay đổi destination của shortlink KHÔNG thay đổi ảnh QR code của nó — bạn có thể cập nhật nơi nó trỏ mà không cần in lại tờ rơi."

---

## 6. QR & Shortlinks (1 phút)

**Hiển thị:** bấm **QR & Shortlinks** trên thanh bên.

**Nói:**
"Đây là thư viện tất cả shortlinks và QR codes của bạn."

**Chỉ ra:**
- Bảng: Code (ví dụ `/go/summer-special`), Destination, ảnh QR thực, nút Download PNG bên dưới ảnh, trạng thái active/disabled, và số clicks.
- Nút **Create Shortlink** — các trường: Code, Label, Destination URL, Campaign, và các trường UTM.
- Mỗi dòng có nút Edit, Enable/Disable, và Delete.

---

## 7. Customer Service (1 phút)

**Hiển thị:** bấm **Customer Service** trên thanh bên.

**Nói:**
"Trang này cho phép bạn đăng một Service Notice tạm thời — ví dụ 'Online ordering tạm thời không khả dụng tại Stone Oak' — hiển thị như banner trên trang Link Hub công khai."

**⚠ Lưu ý quan trọng cần nói trên camera:** "Màn hình này có thể đọc notices từ server, nhưng tạo hoặc chỉnh sửa notices từ màn hình Admin hiện tại vẫn lưu trong trình duyệt cục bộ. KHÔNG dựa vào màn hình này một mình để đăng banner khẩn cho khách hàng cho đến khi đường dẫn lưu phía server được kết nối đầy đủ. Để đăng service notice thực sự, hãy xác nhận notice hiển thị trên Link Hub công khai sau khi lưu."

---

## 8. Staff Training (1 phút)

**Hiển thị:** bấm **Staff Training** trên thanh bên.

**Nói:**
"Staff Training hoạt động y hệt trình chỉnh sửa Customer Link Hub — cùng các tab Buttons/Sections/Page Settings — ngoại trừ trang này là riêng tư. Nó được đánh dấu Unlisted, ẩn khỏi search engine, và không xuất hiện bất kỳ đâu trên trang Customer Link Hub công khai, bất kể bạn thêm gì vào đây."

**Chỉ ra:** banner cảnh báo màu tím "Staff Training — Internal Use Only" ở đầu trình chỉnh sửa trang này, và thực tế Visibility mặc định là Unlisted/Staff Only.

**Quy tắc:** "Không bao giờ đổi destination type của video Staff Training thành thứ khiến nó hiện trên trang khách hàng — hệ thống sẽ cảnh báo nếu bạn thử, nhưng luôn kiểm tra kỹ."

---

## 9. Scheduling (30 giây)

**Hiển thị:** bấm **Scheduling** trên thanh bên.

**Nói:**
"Đây là view calendar kết hợp của mọi thứ có ngày bắt đầu hoặc kết thúc — buttons, pages, bất kỳ thứ gì có giới hạn thời gian — để bạn nhìn một lượt trang nào sắp lên live hoặc hết hạn."

---

## 10. Blog Composer (2–3 phút)

**Hiển thị:** bấm **Blog** trên thanh bên.

**Nói:**
"Blog là nơi viết và quản lý các bài post — tin khuyến mãi, tin tuyển dụng, thông báo giờ lễ, sự kiện. Mỗi bài post có trạng thái: Draft, Scheduled, Published, Archived."

**Chỉ ra:**
- Danh sách bài post: tiêu đề, excerpt, status badge, tác giả, ngày publish, ngày scheduled.
- Nút **Create Post** — mở Blog Composer.
- Trong Blog Composer:
  - Chọn template sẵn có: Promotion Post, New Item Post, Holiday Hours, Event, Hiring, Store Update.
  - Trường: Title, Short Excerpt, nội dung rich text (Quill editor với emoji picker), Caption + Hashtags cho social.
  - CTA Button: Label + URL.
  - Media upload: kéo thả hoặc bấm để upload ảnh/video.
  - Publish Settings: Status, Schedule Date/Time, Slug, Featured Image.
  - Nút: Save Draft, Schedule Post, Publish Now.

---

## 11. Locations (1–2 phút)

**Hiển thị:** bấm **Locations** trên thanh bên.

**Nói:**
"Đây là nguồn thông tin duy nhất cho La Cantera, Stone Oak, và Bandera — địa chỉ, điện thoại, link Toast order và signup, map link, support email, và giờ mở cửa."

**Chỉ ra:**
- Bấm **Add Location** hoặc **Edit** trên một location — hiện đầy đủ các trường.
- "Nhớ loại nút 'Call This Location' từ trình chỉnh sửa Page Editor? Nó đọc trực tiếp từ đây. Thay đổi số điện thoại ở một chỗ này, và mọi nút bất kỳ nào gọi location này đều tự cập nhật — không còn phải sửa cùng một số điện thoại ở năm nơi khác nhau."

**Quy tắc:** "Cập nhật số điện thoại, giờ mở cửa, và link Toast ở đây trước — sau đó kiểm tra các trang tham chiếu location này để xác nhận chúng hiển thị đúng."

---

## 12. SEO Manager (1 phút)

**Hiển thị:** bấm **SEO Manager** trên thanh bên.

**Nói:**
"Đây là tổng quan SEO của mọi trang trên một màn hình — trang nào thiếu title tag hoặc meta description, hiển thị cảnh báo đỏ ngay trong bảng, kèm link Edit một click đến settings của trang đó."

**Chỉ ra:**
- Bảng: Page, Type, SEO Title, Meta Description, OG Image, Indexing status, nút Edit.
- Thẻ SEO Tips bên dưới — hướng dẫn thực tế về độ dài title, description, và lý do Staff Training được loại trừ khỏi indexing.

---

## 13. QR / Link Health / Analytics / Audit Log (1–2 phút)

**Hiển thị:** bấm qua từng trang.

**Nói cho Link Health:**
"Hệ thống kiểm tra mọi link bên ngoài trên trang web định kỳ và đánh dấu bất kỳ thứ gì bị hỏng, chuyển hướng, hoặc timeout — để khách hàng không bao giờ bấm nút Order Online hỏng mà bạn không biết. Bấm 'Check links now' để chạy kiểm tra."

**Nói cho Analytics:**
"Số liệu page views và clicks thực — tổng buttons, live vs hidden, views và clicks trong 24 giờ qua, và buttons nào được bấm nhiều nhất."

**Nói cho Audit Log:**
"Mọi thay đổi bất kỳ ai thực hiện trong Admin này — tạo trang, chỉnh sửa nút, publish, xóa — được ghi lại ở đây với người thực hiện và thời gian. Nếu có gì không đúng, kiểm tra đây trước."

---

## 14. Settings & Users (30 giây)

**Hiển thị:** bấm **Settings** sau đó **Users**.

**Nói:**
"Settings kiểm soát các thứ toàn cục — link Instagram/Facebook, headline text của trang, và copy trang marketing signup. Users là nơi xem tài khoản của bạn và vai trò — Super Admin, Marketing Manager, Store Manager, Viewer."

- Bấm **Profile** trên avatar góc trên bên trái để đổi mật khẩu: Current Password, New Password, Confirm New Password.

**Quy tắc cần nói rõ:** "Mỗi thành viên chỉ nên có vai trò thấp nhất có thể để làm công việc của họ — tài khoản Store Manager hay Content Editor không bao giờ cần quyền Super Admin."

---

## 15. Forms ⚠ CHƯA HOẠT ĐỘNG (bỏ qua hoặc chỉ xem qua)

**Nếu bạn hiển thị trang này, nói:**
"Giống như Automations, đây là bản xem trước — bạn có thể thiết kế form ở đây, nhưng chưa có cách nào để khách hàng thực sự gửi form, và không có gì được lưu vĩnh viễn. Hãy coi đây là mockup, không phải tính năng hoạt động, cho đến khi nó được xây dựng lại."

---

## 16. Media Library (30 giây)

**Hiển thị:** bấm **Media Library** trên thanh bên.

**Nói:**
"Thư viện quản lý các file đã upload — ảnh, video. Tải lên bằng cách kéo thả hoặc bấm nút Upload. Mỗi file có nút Copy URL và Delete."

**⚠ Lưu ý:** "Upload ghi nhớ vào server, nhưng nếu server không khả dụng, file nhỏ sẽ được lưu tạm trong trình duyệt."

---

## 17. Automations ⚠ CHƯA HOẠT ĐỘNG (bỏ qua hoặc chỉ xem qua)

**Nếu bạn hiển thị trang này, nói:**
"Trang này là bản xem trước của tính năng tương lai — các quy tắc tự động như 'khi campaign kết thúc, ẩn nút của nó tự động.' Hiện tại chưa kết nối gì live. Vui lòng không cấu hình ở đây với kỳ vọng nó thực sự chạy."

---

## 18. Project Overview (30 giây)

**Hiển thị:** bấm **Project Hub** (từ Quick Actions trên Dashboard).

**Nói:**
"Đây là tổng quan về dự án Link Hub 2.0 — mục đích, đội phụ trách, tài liệu liên quan, và danh sách các trang store với địa chỉ thực."

---

## 19. Kết luận (30 giây)

**Nói:**
"Đó là toàn bộ tour. Core của hệ thống — Pages, Buttons, Sections, Templates, Campaigns, Locations, SEO, QR, Shortlinks, Link Health, Analytics, và Staff Training — đang hoạt động hôm nay và không yêu cầu ai phải đụng code. Một vài trang mới hơn — Forms, Automations, Media Library, và chỉnh sửa Customer Service notice — vẫn còn hoạt động một phần hoặc chỉ cục bộ trong trình duyệt; hãy cẩn thận và xác nhận output công khai trước khi dựa vào chúng. Nếu có gì bị hỏng hoặc khó hiểu, kiểm tra Audit Log trước, sau đó liên hệ developer."

---

## Phụ lục: Bảng tra nhanh — Thật vs Xem trước (tính đến 2026-07-05)

| Module | Trạng thái |
|--------|------------|
| Pages, Buttons, Sections, Page Settings, Publish/Rollback | ✅ Thật, đã test live |
| Templates | ✅ Thật, đã test live |
| Campaigns (cơ bản) | ✅ Thật, đã test live |
| UTM Builder / Shortlinks / QR | ✅ Thật |
| Locations + location-derived buttons (Call/Directions/Hours/Support) | ✅ Thật, đã test live |
| Staff Training | ✅ Thật, đúng cách ly khỏi public |
| SEO Manager | ✅ Thật |
| Link Health, Analytics, Audit Log | ✅ Thật |
| Scheduling (chỉ start/end date, chưa có lặp lại theo ngày trong tuần) | ✅ Thật, một phần |
| Blog Composer | ✅ Thật |
| Project Overview | ✅ Thật |
| Customer Service (notice banners) | ⚠ Một phần — có thể đọc notices từ server, nhưng Admin tạo/sửa hiện lưu cục bộ |
| Media Library | ⚠ Một phần — upload ghi nhớ server nhưng có thể fallback về data URL cục bộ |
| Forms | ⚠ Chỉ UI — không có form công khai, không có lưu submission |
| Automations | ⚠ Chỉ UI — không có engine chạy quy tắc |

