import math
# mat will be used below...

print("Hello World")
print("$" * 10)

x = 1
g = 888
message = """Hi Cha,
This is \"Eli\"
blah blah \nblah
"""

x = len(message)
print(x)
print(message[0])
print(message[-1])
print(message[0:3])
print(message)

first = "Eli"
last = "Agbayani"
full = f"{first} {last} {2*4} {len(message)}"
print(full)

# use comments here...
course = "    Python Programming ito.    "
print(course.upper())
print(course.lower())
print(course.title())
print(course.strip())  # like trim() to PHP
print(course.find("ito"))  # return the index of the substring
print(course.replace("y", "i"))
print("pro" in course)  # returns False
print("Pro" in course)  # returns True
print("Cha sama" not in course)  # returns True
# --------------------------------------------------
print(10 + 3)
print(10 - 3)
print(10 * 3)
print(10 / 3)
print(10 // 3)  # division to return the whole no.
print(10 % 3)  # modulus operator; returns the remainder
print(10 ** 3)  # left to the exponent of right e.g. 1000

# both are the same:
x = x + 3
x += 3
# --------------------------------------------------
print("-=" * 30)
print(round(2.9))
print(abs(-2.9))
print(math.ceil(2.1))
print(math.factorial(4))  # return 24 OK
# to search for other math methods search in google "python 3 math module"
# --------------------------------------------------
# print("-=" * 30)
# x = input("x: ")
# print(f"type of x is {type(x)}")
# y = int(x) + 1
# print(f"sum is {y}")

# sample of type converter functions. Converts to integer, to float, and so on...
# int(x)
# float(x)
# bool(x)
# str(x)

# Falsy values in Python
# ""
# 0 -> the number zero
# None -> is an object none
# --------------------------------------------------
print("-=" * 30)
temp = 15
if temp > 30:
    print("It's warm")
    print("Drink water")
elif temp > 20:
    print("It's nice")
else:
    print("It's cold")
print("Done")

# ternary operator
age = 17
message = "Eligible" if age >= 18 else "Not eligible"
print(message)

# 3 logical operators: and, or, not
# similar with PHP's && || !(condition goes here)

# For Loops
for number in range(3):
    print("Attempt", number)
print("-=" * 30)
for number in range(1, 4):
    print("Attempt", number)
print("-=" * 30)
for number in range(1, 10, 2):
    print("Attempt", number)
else:
    print("Attempted 3 times and failed")
print("-=" * 30)
# to break inside a loop use: break

# -------------------------------------------------- Iterables
print("-=" * 30)
print(type(5))              # <class 'int'>
print(type(range(5)))       # <class 'range'> -> sample of a complex type

for x in range(3):
    print(x)
for x in "Python":
    print(x)
for x in ["1", "Eli", "boy", 12.5]:
    print(x)

# While Loops
num = 100
while num > 0:
    print(num)
    num = num // 2

# command = ""
# while command.lower() != "quit":
#     command = input("Enter your input here: ")
#     print("What was your input: ", command)

# while True:
#     command = input("Enter your input here: ")
#     print("What was your input: ", command)
#     if command.lower() == "quit":
#         break

print("-=" * 30)
i = 0
for x in range(1, 10):
    if x % 2 == 0:
        print(x)
        i += 1
print(f"We have {i} even numbers.")

# --------------------------------------------------
print("-=" * 30)

# --------------------------------------------------
# print("-=" * 30)
# --------------------------------------------------
# print("-=" * 30)
# --------------------------------------------------
# print("-=" * 30)
# --------------------------------------------------
# print("-=" * 30)
# --------------------------------------------------
# print("-=" * 30)
# --------------------------------------------------
# print("-=" * 30)
